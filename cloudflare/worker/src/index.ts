/**
 * Runs the reviewer's step of a review in a Cloudflare sandbox, for review-central.
 *
 * review-central clones the repository and decides what to review on its own
 * worker, as it does for Docker. For this sandbox it packs the checkout into the
 * bucket, asks here for a run, and reads what the reviewer prints as it comes.
 * When the reviewer is done, what it wrote goes back into the bucket and the
 * sandbox is destroyed.
 *
 *   POST   /runs/{name}   start a sandbox, run the script, stream its output
 *   DELETE /runs/{name}   destroy a sandbox that was left behind
 *   GET    /health        whether the Worker is there and has its settings
 *   PUT    /files/{key}   put a file in the bucket     } for local development,
 *   GET    /files/{key}   read one                    } where the bucket is a stand-in
 *   DELETE /files/{key}   remove one, or all under it } only this Worker can reach
 *
 * Every request carries the token the Worker was deployed with. The bucket is how
 * files travel, so neither a checkout nor a session is limited by what a request
 * may carry.
 *
 * The sandbox starts from Cloudflare's own Debian image, and the run brings a
 * script that installs what the reviewer needs. That install happens once per
 * version of the script, in a sandbox of its own (the Durable Object named
 * prepare-<version>, asked by POST /prepare or by the first run that finds no
 * snapshot), which keeps a snapshot and the install's output in the bucket.
 * Every run starts from the snapshot with the tools in place. Only when no
 * snapshot can be had does a run install in its own sandbox, its output
 * streamed with the rest. Nothing is built or pushed to deploy this.
 */
import { DurableObject } from "cloudflare:workers";

/**
 * Which code this is, for review-central's setup page to compare with its own
 * copy: raised with every change here, so a Worker that was not deployed again
 * after one is told apart. tests/Feature/CloudflareWorkerVersionTest.php fails
 * on a change that leaves it as it was.
 */
const WORKER_VERSION = "2026-11-19.1";

interface Env {
  REVIEW_SANDBOX: DurableObjectNamespace<ReviewSandbox>;
  BUCKET: R2Bucket;
  /** The secret review-central sends as its bearer token. */
  API_TOKEN: string;
  /** The image a sandbox starts from, when it is not one wrangler.jsonc builds under the name `reviewer`. */
  IMAGE?: string;
}

/** Cloudflare's own image: a plain Debian, kept current by them. */
const MANAGED_IMAGE = "cloudflare/debian-trixie";

type Instance = "lite" | "basic" | "standard-1" | "standard-2" | "standard-3" | "standard-4";

interface RunSpec {
  /** The object in the bucket to unpack at /: `work/` and, where a session is kept, `home/reviewer/`. */
  input: string;
  /** The object to write what the reviewer left: `home/reviewer/` and the files named in `keep`. */
  output: string;
  /** What installs the reviewer's tools, by `sh -c` as root in a sandbox that holds no checkout: once per version in the prepare sandbox, its output kept in the bucket; in the run's own only when no snapshot can be had, its output streamed. */
  setup: string | null;
  /** What names this setup: a sandbox that ran it is kept as a snapshot under this name, and started from next time. */
  setupVersion: string | null;
  /** Run by `sh -c` in /work, as the reviewer. */
  script: string;
  /** The only variables the script gets, besides PATH. */
  env: Record<string, string>;
  /** Files in /work to hand back, where the reviewer wrote them. */
  keep: string[];
  /** Seconds the script may take. */
  timeout: number;
  instance: Instance;
  /**
   * The repository's vendor/, of its destination branch, as one archive: put at
   * `file` before the script when the bucket has `key`, and the one the script
   * wrote at `<file>.new` put in the bucket after it. Null without a composer.json.
   */
  cache?: { key: string; file: string } | null;
}

/** What a cache may be called: the review's own names, never a path elsewhere. */
const CACHE_KEY = /^review\/cache\/[A-Za-z0-9_-]+\/vendor-[a-z0-9-]+\.tar$/;
const CACHE_FILE = /^\/cache\/composer\/vendor-[a-z0-9-]+\.tar$/;

interface PrepareSpec {
  setup: string;
  setupVersion: string;
  instance: Instance;
}

type PrepareResult = { ok: true; made: boolean; seconds: number } | { ok: false; error: string; seconds: number };

/** The snapshot a setup's tools were kept as, when there is one. */
async function readSnapshot(env: Env, key: string): Promise<ContainerSnapshot | null> {
  const snapshot = await env.BUCKET.get(key)
    .then((object) => object?.json<ContainerSnapshot>() ?? null)
    .catch(() => null);

  return snapshot !== null && typeof snapshot.id === "string" ? snapshot : null;
}

const INSTANCES: Instance[] = ["lite", "basic", "standard-1", "standard-2", "standard-3", "standard-4"];

/**
 * Who the reviewer runs as: not root, as under Docker. Its tools expect that, and
 * some refuse to work unattended as root.
 */
const REVIEWER = "1000:1000";

/** Six hours is the longest Cloudflare lets an instance sit; a review is done long before. */
const LONGEST = 6 * 60 * 60;

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url = new URL(request.url);

    if (!env.API_TOKEN || !(await sameToken(request.headers.get("Authorization") ?? "", `Bearer ${env.API_TOKEN}`))) {
      return json({ error: "Not allowed." }, 401);
    }

    if (request.method === "GET" && url.pathname === "/health") {
      // With ?setup=<version>: whether that setup's tools are prepared.
      const setup = url.searchParams.get("setup");
      const snapshot = setup !== null && /^[a-f0-9]{8,64}$/.test(setup) ? (await readSnapshot(env, `review/snapshots/${setup}.json`)) !== null : undefined;

      return json({ ok: true, version: WORKER_VERSION, bucket: env.BUCKET !== undefined, snapshot });
    }

    // Prepares a setup's tools ahead of the first review, which would otherwise
    // wait for them. Answers when it is done, a few minutes for a first one.
    if (request.method === "POST" && url.pathname === "/prepare") {
      const body = (await request.json().catch(() => null)) as Record<string, unknown> | null;
      const setup = body?.setup;
      const setupVersion = body?.setupVersion;

      if (typeof setup !== "string" || setup === "" || typeof setupVersion !== "string" || !/^[a-f0-9]{8,64}$/.test(setupVersion)) {
        return json({ error: "setup is a script and setupVersion a hash of it." }, 422);
      }

      const instance = INSTANCES.find((name) => name === body?.instance) ?? "standard-1";
      const result = await env.REVIEW_SANDBOX.getByName(`prepare-${setupVersion}`).prepare({ setup, setupVersion, instance });

      return json(result, result.ok ? 200 : 500);
    }

    // The bucket through the Worker. A deployed Worker is not used this way:
    // review-central reaches its bucket directly, and a request is limited in size.
    const file = url.pathname.match(/^\/files\/(review\/[A-Za-z0-9._\/-]{1,400})$/);

    if (file !== null && !file[1].includes("..")) {
      return files(request, env, file[1]);
    }

    const run = url.pathname.match(/^\/runs\/([A-Za-z0-9][A-Za-z0-9._-]{0,99})$/);

    if (run === null) {
      return json({ error: "No such address." }, 404);
    }

    const sandbox = env.REVIEW_SANDBOX.getByName(run[1]);

    if (request.method === "DELETE") {
      await sandbox.destroy();

      return json({ destroyed: true });
    }

    if (request.method !== "POST") {
      return json({ error: "POST starts a run, DELETE destroys one." }, 405);
    }

    const spec = readSpec(await request.json().catch(() => null));

    if (typeof spec === "string") {
      return json({ error: spec }, 422);
    }

    return sandbox.run(spec);
  },
} satisfies ExportedHandler<Env>;

export class ReviewSandbox extends DurableObject<Env> {
  constructor(ctx: DurableObjectState, env: Env) {
    super(ctx, env);

    if (ctx.container === undefined) {
      throw new Error("This Durable Object has no container: check the containers block in wrangler.jsonc.");
    }
  }

  /**
   * One review: a fresh sandbox, the script, and what it left. The answer is a
   * stream of server-sent events:
   *
   *   event: note     data: "a line of this Worker's own"
   *   event: output   data: "a piece of what the script printed"
   *   event: exit     data: {"exitCode": 0, "seconds": 212, "kept": true}
   *   event: error    data: "why there was no run"
   *
   * A piece of output may end anywhere, so it is appended, not read as a line.
   */
  async run(spec: RunSpec): Promise<Response> {
    const { readable, writable } = new TransformStream<Uint8Array, Uint8Array>();
    const writer = writable.getWriter();
    const encoder = new TextEncoder();
    let open = true;

    const send = async (event: string, data: unknown): Promise<void> => {
      if (!open) {
        return;
      }

      try {
        await writer.write(encoder.encode(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`));
      } catch {
        // Nobody is reading any more: the review was cut short on the other side.
        open = false;
      }
    };

    // A line that says nothing, so a proxy on the way does not take the silence of
    // a reviewer that is thinking for a dead connection.
    const alive = setInterval(() => {
      if (open) {
        writer.write(encoder.encode(": keep-alive\n\n")).catch(() => {
          open = false;
        });
      }
    }, 5000);

    this.ctx.waitUntil(
      this.review(spec, send, () => open)
        .catch((error: unknown) => send("error", error instanceof Error ? error.message : String(error)))
        .finally(async () => {
          clearInterval(alive);
          // Whatever happened, the sandbox does not outlive its review.
          await this.destroy();
          await writer.close().catch(() => undefined);
        }),
    );

    return new Response(readable, {
      headers: { "Content-Type": "text/event-stream", "Cache-Control": "no-cache" },
    });
  }

  /** The install of a setup in progress here, which a second caller waits for instead of starting another. */
  private building: Promise<PrepareResult> | null = null;

  /**
   * Prepares the tools of a setup: a fresh sandbox from the image runs the setup,
   * and a snapshot of it is kept for reviews to start from. Called on the Durable
   * Object named for the setup, so there is one at a time for each; a call while
   * one is going waits for it. Nothing is done when the snapshot is there.
   */
  async prepare(spec: PrepareSpec): Promise<PrepareResult> {
    const key = `review/snapshots/${spec.setupVersion}.json`;

    if ((await readSnapshot(this.env, key)) !== null) {
      return { ok: true, made: false, seconds: 0 };
    }

    this.building ??= this.build(spec, key).finally(() => {
      this.building = null;
    });

    return this.building;
  }

  private async build(spec: PrepareSpec, key: string): Promise<PrepareResult> {
    const container = this.ctx.container!;
    const started = Date.now();

    try {
      await this.destroy();
      container.start({ entrypoint: ["sleep", "infinity"], enableInternet: true, instance: spec.instance, image: container.images.reviewer ?? this.env.IMAGE ?? MANAGED_IMAGE });
      await this.ready();
      await container.setInactivityTimeout(3600 * 1000);

      // The tools, in a sandbox that holds nothing of anyone's: what is kept of
      // it is the same for every tenant.
      const process = await container.exec(["sh", "-c", spec.setup], { cwd: "/", env: { DEBIAN_FRONTEND: "noninteractive", HOME: "/root" }, stderr: "combined" });
      const output = await process.output();
      const log = new TextDecoder().decode(output.stdout);
      await this.env.BUCKET.put(`review/snapshots/${spec.setupVersion}.log`, log).catch(() => undefined);

      if (output.exitCode !== 0) {
        return { ok: false, error: `the setup ended with exit ${output.exitCode}: ${log.slice(-1500)}`, seconds: Math.round((Date.now() - started) / 1000) };
      }

      const taken = await container.snapshotContainer({ name: `review-tools-${spec.setupVersion}` });
      await this.env.BUCKET.put(key, JSON.stringify(taken));

      return { ok: true, made: true, seconds: Math.round((Date.now() - started) / 1000) };
    } catch (error) {
      return { ok: false, error: error instanceof Error ? error.message : String(error), seconds: Math.round((Date.now() - started) / 1000) };
    } finally {
      await this.destroy();
    }
  }

  async destroy(): Promise<void> {
    const container = this.ctx.container;

    if (container?.running) {
      await container.destroy().catch(() => undefined);
    }
  }

  private async review(spec: RunSpec, send: (event: string, data: unknown) => Promise<void>, wanted: () => boolean): Promise<void> {
    const container = this.ctx.container!;
    // An image wrangler.jsonc builds, one named in the settings, or Cloudflare's own.
    const image = container.images.reviewer ?? this.env.IMAGE ?? MANAGED_IMAGE;
    // The image's own command may end at once, and a sandbox stops with it.
    const always = { entrypoint: ["sleep", "infinity"], enableInternet: true, instance: spec.instance };
    const snapshotKey = spec.setup !== null && spec.setupVersion !== null ? `review/snapshots/${spec.setupVersion}.json` : null;

    // One left by a run that never ended is not this review's to continue.
    await this.destroy();

    const started = Date.now();

    // The reviewer installs the repository's packages and calls its AI provider,
    // so it has the internet. It gets no credential of the repository's host.
    //
    // From the snapshot of a sandbox that ran this setup. Made once, by a
    // sandbox of its own for that setup, which a review that finds none asks
    // and waits for: two reviews at once do not both install.
    let prepared = false;

    for (let attempt = 0; attempt < 2 && !prepared && snapshotKey !== null; attempt++) {
      let snapshot = await readSnapshot(this.env, snapshotKey);

      if (snapshot === null) {
        await send("note", "The sandbox's tools are not prepared yet: preparing them, once, which takes a few minutes.");
        const made = await this.env.REVIEW_SANDBOX.getByName(`prepare-${spec.setupVersion}`).prepare({ setup: spec.setup!, setupVersion: spec.setupVersion!, instance: spec.instance });

        if (!made.ok) {
          // Snapshots are a beta at Cloudflare: when none can be made, this
          // review installs in its own sandbox, as before there were any.
          await send("note", `The sandbox's tools could not be prepared (${made.error.slice(0, 300)}); installing them for this review alone.`);
          break;
        }

        snapshot = await readSnapshot(this.env, snapshotKey);
      }

      if (snapshot === null) {
        break;
      }

      try {
        container.start({ ...always, containerSnapshot: { id: snapshot.id } });
        await this.ready();
        prepared = true;
        await send("note", `Sandbox started (${spec.instance}) from the snapshot with its tools installed.`);
      } catch {
        // Expired, or of an image that is gone: prepared again on the next attempt.
        await this.destroy();
        await this.env.BUCKET.delete(snapshotKey).catch(() => undefined);
      }
    }

    if (!prepared) {
      container.start({ ...always, image });
      await this.ready();
      await send("note", `Sandbox started (${spec.instance}, ${image}).`);
    }

    await container.setInactivityTimeout(Math.min(spec.timeout + 900, LONGEST) * 1000);

    const decoder = new TextDecoder();

    /** Runs a command and streams what it prints; resolves with its exit code, or 143 when nobody reads any more. */
    const streamed = async (command: string[], options: ContainerExecOptions): Promise<number> => {
      const process = await container.exec(command, { cwd: "/", ...options, stderr: "combined" });

      if (process.stdout !== null) {
        for await (const chunk of process.stdout as ReadableStream<Uint8Array>) {
          await send("output", decoder.decode(chunk, { stream: true }));

          if (!wanted()) {
            process.kill();

            return 143;
          }
        }
      }

      return process.exitCode;
    };

    // No snapshot to be had, and a setup to run: installed here, for this review
    // alone, as a last resort.
    if (!prepared && spec.setup !== null) {
      const installed = await streamed(["sh", "-c", spec.setup], { env: { DEBIAN_FRONTEND: "noninteractive", HOME: "/root" } });

      if (installed !== 0) {
        await send("exit", { exitCode: installed, seconds: Math.round((Date.now() - started) / 1000), kept: false });

        return;
      }
    }

    const input = await this.env.BUCKET.get(spec.input);

    if (input === null) {
      throw new Error(`Nothing in the bucket at ${spec.input}.`);
    }

    // Straight from the bucket into tar: tar and gzip are in any image, so the
    // sandbox needs nothing of ours to be handed its files.
    await this.must(
      ["sh", "-c", `mkdir -p /work /home/reviewer && tar -xzf - -C / --no-same-owner && chown -R ${REVIEWER} /work /home/reviewer`],
      "unpack the checkout",
      input.body,
    );

    const cache = spec.cache && CACHE_KEY.test(spec.cache.key) && CACHE_FILE.test(spec.cache.file) ? spec.cache : null;
    let restored = false;

    if (cache !== null) {
      restored = await this.restoreCache(cache).catch(async (error: unknown) => {
        await send("note", `Could not fetch vendor/ from the bucket, so Composer installs it: ${error instanceof Error ? error.message : String(error)}`);

        return false;
      });

      if (restored) {
        await send("note", "Fetched vendor/ for this composer.lock from the bucket.");
      }
    }

    const exitCode = await streamed(
      ["timeout", "--signal=TERM", "--kill-after=30", String(spec.timeout), "sh", "-c", spec.script],
      { cwd: "/work", env: { ...spec.env, HOME: "/home/reviewer" }, user: REVIEWER },
    );
    let kept = false;

    // What the reviewer left, also when it failed: a session that was resumed and
    // a report that is half there are both the caller's to judge.
    if (wanted()) {
      kept = await this.keep(spec).catch(async (error: unknown) => {
        await send("note", `Could not hand back the reviewer's files: ${error instanceof Error ? error.message : String(error)}`);

        return false;
      });
    }

    // A vendor/ of the destination branch the script kept beside the one it was
    // given. Never in the way of the review: without it the next one installs again.
    if (cache !== null && wanted()) {
      await this.storeCache(cache)
        .then((stored) => (stored ? send("note", "Kept the destination branch's vendor/ in the bucket for the next review of this repository.") : undefined))
        .catch((error: unknown) => send("note", `Could not keep vendor/ in the bucket: ${error instanceof Error ? error.message : String(error)}`));
    }

    await send("exit", { exitCode, seconds: Math.round((Date.now() - started) / 1000), kept });
  }

  /**
   * Puts the repository's vendor/ archive where the script looks for it, when the
   * bucket has one. The directory is there either way, for Composer's own cache.
   */
  private async restoreCache(cache: { key: string; file: string }): Promise<boolean> {
    await this.must(["sh", "-c", `mkdir -p /cache/composer && chown ${REVIEWER} /cache/composer`], "make the cache directory");

    const object = await this.env.BUCKET.get(cache.key);

    if (object === null) {
      return false;
    }

    await this.must(["sh", "-c", `cat > "$0" && chown ${REVIEWER} "$0"`, cache.file], "put vendor/ in place", object.body);

    return true;
  }

  /**
   * Puts the archive the script wrote beside the one it was given (`<file>.new`)
   * in the bucket, when it wrote one. Only a plain file: the reviewer could have
   * left a link in its place.
   */
  private async storeCache(cache: { key: string; file: string }): Promise<boolean> {
    const file = `${cache.file}.new`;
    const measured = await (await this.ctx.container!.exec(["sh", "-c", '[ -f "$0" ] && [ ! -L "$0" ] && stat -c %s "$0"', file])).output();
    const size = Number(new TextDecoder().decode(measured.stdout).trim());

    if (measured.exitCode !== 0 || !Number.isInteger(size) || size === 0) {
      return false;
    }

    const archive = await this.ctx.container!.exec(["cat", file], { stderr: "ignore" });

    if (archive.stdout === null) {
      return false;
    }

    await this.env.BUCKET.put(cache.key, (archive.stdout as ReadableStream<Uint8Array>).pipeThrough(new FixedLengthStream(size)));

    return (await archive.exitCode) === 0;
  }

  /**
   * Packs the reviewer's home and the files it was asked for, and puts the
   * archive in the bucket. Links are left out: the other side unpacks it.
   */
  private async keep(spec: RunSpec): Promise<boolean> {
    await this.must(
      [
        "sh",
        "-c",
        'cd /work || exit 1; kept=""; for file in "$@"; do if [ -f "$file" ] && [ ! -L "$file" ]; then kept="$kept work/$file"; fi; done; ' +
          "cd / && tar -czf /tmp/review-output.tar.gz --ignore-failed-read home/reviewer $kept",
        "keep",
        ...spec.keep,
      ],
      "pack the reviewer's files",
    );

    const measured = await (await this.ctx.container!.exec(["stat", "-c", "%s", "/tmp/review-output.tar.gz"])).output();
    const size = Number(new TextDecoder().decode(measured.stdout).trim());
    const archive = await this.ctx.container!.exec(["cat", "/tmp/review-output.tar.gz"], { stderr: "ignore" });

    if (measured.exitCode !== 0 || !Number.isInteger(size) || archive.stdout === null) {
      return false;
    }

    // The bucket wants to know how much is coming before it takes a stream.
    await this.env.BUCKET.put(spec.output, (archive.stdout as ReadableStream<Uint8Array>).pipeThrough(new FixedLengthStream(size)));

    return (await archive.exitCode) === 0;
  }

  /**
   * start() returns before the sandbox takes commands; this waits until it does.
   */
  private async ready(): Promise<void> {
    const container = this.ctx.container!;
    let last: unknown = null;

    for (let attempt = 0; attempt < 120; attempt++) {
      try {
        const process = await container.exec(["true"], { stdout: "ignore", stderr: "ignore" });

        if ((await process.exitCode) === 0) {
          return;
        }
      } catch (error) {
        last = error;
      }

      await new Promise((resolve) => setTimeout(resolve, 500));
    }

    throw new Error(`The sandbox did not start within a minute${last instanceof Error ? `: ${last.message}` : "."}`);
  }

  private async must(command: string[], what: string, stdin?: ReadableStream): Promise<void> {
    const process = await this.ctx.container!.exec(command, stdin === undefined ? { stderr: "combined" } : { stderr: "combined", stdin });
    const output = await process.output();

    if (output.exitCode !== 0) {
      throw new Error(`Could not ${what} (exit ${output.exitCode}): ${new TextDecoder().decode(output.stdout).slice(-500)}`);
    }
  }
}

async function files(request: Request, env: Env, key: string): Promise<Response> {
  if (request.method === "PUT") {
    // Read whole first: the bucket wants a length, and this is for a machine's own Worker.
    await env.BUCKET.put(key, await request.arrayBuffer());

    return json({ stored: key });
  }

  if (request.method === "GET") {
    const object = await env.BUCKET.get(key);

    return object === null ? json({ error: "Nothing there." }, 404) : new Response(object.body, { headers: { "Content-Type": "application/octet-stream" } });
  }

  if (request.method === "DELETE") {
    const listed = await env.BUCKET.list({ prefix: key });
    const keys = listed.objects.map((object) => object.key);

    if (keys.length > 0) {
      await env.BUCKET.delete(keys);
    }

    return json({ removed: keys.length });
  }

  return json({ error: "PUT, GET or DELETE." }, 405);
}

/**
 * The run that was asked for, or what is wrong with the request.
 */
function readSpec(body: unknown): RunSpec | string {
  if (typeof body !== "object" || body === null) {
    return "The body is not a JSON object.";
  }

  const spec = body as Record<string, unknown>;
  const key = (value: unknown): value is string => typeof value === "string" && /^[A-Za-z0-9][A-Za-z0-9._\/-]{0,500}$/.test(value) && !value.includes("..");

  if (!key(spec.input) || !key(spec.output)) {
    return "input and output name an object in the bucket.";
  }

  if (typeof spec.script !== "string" || spec.script === "") {
    return "script is what to run.";
  }

  if (spec.setup !== undefined && spec.setup !== null && typeof spec.setup !== "string") {
    return "setup is a script, or left out.";
  }

  if (spec.setupVersion !== undefined && spec.setupVersion !== null && (typeof spec.setupVersion !== "string" || !/^[a-f0-9]{8,64}$/.test(spec.setupVersion))) {
    return "setupVersion is a hash of the setup, or left out.";
  }

  const env = typeof spec.env === "object" && spec.env !== null ? (spec.env as Record<string, unknown>) : {};

  if (Object.entries(env).some(([name, value]) => !/^[A-Za-z_][A-Za-z0-9_]*$/.test(name) || typeof value !== "string")) {
    return "env holds strings under names a shell takes.";
  }

  const keep = Array.isArray(spec.keep) ? spec.keep : [];

  if (keep.some((file) => typeof file !== "string" || !/^[A-Za-z0-9._][A-Za-z0-9._-]{0,99}$/.test(file) || file.startsWith(".."))) {
    return "keep names files in the work directory.";
  }

  const timeout = Number(spec.timeout);

  if (!Number.isInteger(timeout) || timeout < 1 || timeout > LONGEST - 900) {
    return "timeout is a number of seconds, at most five hours.";
  }

  const instance = INSTANCES.find((name) => name === spec.instance) ?? "standard-1";

  return {
    input: spec.input,
    output: spec.output,
    setup: typeof spec.setup === "string" && spec.setup !== "" ? spec.setup : null,
    setupVersion: typeof spec.setupVersion === "string" ? spec.setupVersion : null,
    script: spec.script,
    env: env as Record<string, string>,
    keep: keep as string[],
    timeout,
    instance,
  };
}

/**
 * Compared by their hashes, so how long it takes says nothing of where they differ.
 */
async function sameToken(given: string, expected: string): Promise<boolean> {
  const encoder = new TextEncoder();
  const [one, other] = await Promise.all([
    crypto.subtle.digest("SHA-256", encoder.encode(given)),
    crypto.subtle.digest("SHA-256", encoder.encode(expected)),
  ]);
  const a = new Uint8Array(one);
  const b = new Uint8Array(other);
  let different = 0;

  for (let index = 0; index < a.length; index++) {
    different |= a[index] ^ b[index];
  }

  return different === 0;
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}
