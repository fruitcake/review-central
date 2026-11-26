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
 *   DELETE /runs/{name}   destroy a sandbox that was left behind, or one kept warm
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
 *
 * The AI providers' keys never enter the sandbox. The reviewer gets a placeholder
 * in their place, and its HTTPS requests to the provider's host are handed to
 * KeyProxy here, which puts the key in for the placeholder on the way out. What
 * the reviewer reads, or is talked into, cannot reach a key it does not have.
 */
import { DurableObject, WorkerEntrypoint } from "cloudflare:workers";

/**
 * Which code this is, for review-central's setup page to compare with its own
 * copy: raised with every change here, so a Worker that was not deployed again
 * after one is told apart. tests/Feature/CloudflareWorkerVersionTest.php fails
 * on a change that leaves it as it was.
 */
const WORKER_VERSION = "2026-11-26.3";

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
  /** The only variables the script gets, besides PATH. A key is in it as its placeholder. */
  env: Record<string, string>;
  /** The keys the sandbox does not get: each put in for its placeholder in a request to its host. */
  credentials: Credential[];
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
  /**
   * The repository for the sandbox to clone itself, before the input is unpacked
   * over it; null when the input holds the checkout. See CLONE.
   */
  clone?: CloneSpec | null;
  /**
   * Keeps the sandbox for this long after the run, for the next review of the same
   * branch to start in. Asked under a name of the branch's own; a run that finds it
   * still there clears what the reviewer could have changed (WARM_RESET) and fetches
   * the new commit instead of starting and cloning. Only with a clone.
   */
  warm?: { seconds: number } | null;
  /** What names this attempt, so the caller can stop it again by name, and no newer one. */
  attempt?: string | null;
}

/** What the sandbox clones, as review-central's Checkout::forSandbox() says it. */
interface CloneSpec {
  /** An https URL. */
  url: string;
  /** The header git would send, which GitGateway adds; null for a repository anyone may read. */
  authorization: string | null;
  /** The ref fetched, under refs/remotes/origin/<name>. */
  ref: string;
  name: string;
  /** The commit checked out. */
  commit: string;
  /** The branch fetched beside it, or null. */
  destination: string | null;
  /** Where the destination is put back to, for a merged pull request; or null. */
  base: string | null;
  /** The commit the last review ended on, whose comparison with this one is fetched too; or null. */
  since: string | null;
}

/** What GitGateway is started with: the host, the repository's path on it, and the header for reading it. */
interface GitGatewayProps {
  host: string;
  path: string;
  authorization: string;
}

/**
 * The clone, by `sh -c` as the reviewer in an empty /work, with the spec's values
 * as its arguments: blobless, as review-central clones, so the merge base is
 * there and a file's contents are fetched when they are read. A commit the ref no
 * longer reaches, after a force push, is fetched by itself. The contents of what
 * the review compares, with the merge base and with the commit the last review
 * ended on, are fetched now, as review-central does before a review: a
 * credential lent for the clone may not outlast it. Where the review writes
 * what the reviewer reads is made the review's own, whatever the pull request
 * committed there, as review-central's Precheck::take() does on the worker.
 *
 * In a warm sandbox the same script runs over a .git that WARM_RESET left only
 * the objects of: git init makes the rest again, the commit the last review ended
 * on tells the fetch what is there already, and the checkout and clean put the
 * working tree back to the commit, vendor/ aside.
 */
const CLONE = `set -e
url=$1 ref=$2 name=$3 commit=$4 destination=$5 base=$6 previous=$7
git -c init.defaultBranch=main init -q --template= .
git remote add origin "$url"
git config remote.origin.promisor true
git config remote.origin.partialclonefilter blob:none
git config gc.auto 0
if [ -n "$previous" ] && git cat-file -e "$previous^{commit}" 2> /dev/null; then
  git update-ref refs/review/previous "$previous"
fi
git fetch -q --filter=blob:none --no-tags origin "+$ref:refs/remotes/origin/$name"
if [ -n "$destination" ] && [ "$destination" != "$name" ]; then
  git fetch -q --filter=blob:none --no-tags origin "+refs/heads/$destination:refs/remotes/origin/$destination"
fi
git cat-file -e "$commit^{commit}" 2> /dev/null || git fetch -q --filter=blob:none --no-tags origin "$commit"
# vendor/ of the last review stays only where Composer manages it: the commit
# has a composer.json, and neither it nor the one before tracks files there.
keep=""
if [ -d vendor ] && [ ! -L vendor ] && [ -n "$previous" ] && git cat-file -e "$commit:composer.json" 2> /dev/null \
  && git cat-file -e "$previous^{commit}" 2> /dev/null \
  && [ -z "$(git ls-tree -r --name-only "$commit" -- vendor | head -n 1)" ] \
  && [ -z "$(git ls-tree -r --name-only "$previous" -- vendor | head -n 1)" ]; then
  keep=1
fi
[ -n "$keep" ] || rm -rf vendor
git checkout -q -f --detach "$commit"
if [ -n "$keep" ]; then git clean -q -ffdx -e /vendor; else git clean -q -ffdx; fi
if [ -n "$base" ]; then
  git update-ref "refs/remotes/origin/$destination" "$base"
fi
if [ -n "$destination" ] && since=$(git merge-base "refs/remotes/origin/$destination" HEAD 2> /dev/null); then
  git diff --no-ext-diff --no-textconv --stat "$since" HEAD > /dev/null || true
fi
if [ -n "$previous" ]; then
  git diff --no-ext-diff --no-textconv --stat "$previous" HEAD > /dev/null 2>&1 || true
fi
rm -rf -- review-context.json review-ticket.md .review-instructions
echo "Cloned $(git rev-parse HEAD)."`;

/** Touched by root just before the review's script runs: what changed after it is the reviewer's doing. */
const REVIEWER_STARTED = "/var/lib/review/reviewer-started";

/**
 * How a command runs as the reviewer. Under the durable_object scheduling policy
 * exec's `user` sets the owner of new files and no more: every process keeps
 * root's capabilities and can become root again. setpriv takes them away for good
 * (an empty bounding set, and no new privileges), so file permissions hold for
 * the reviewer and it cannot change what root owns. Used only where PROBE showed
 * it works; a sandbox where it does not is never kept for another review.
 */
/**
 * How often the step's output is followed again after the connection to it was lost,
 * and how long it waits before each time: the step goes on in the sandbox meanwhile.
 */
const MOST_REATTACHES = 8;
const REATTACH_WAIT_MS = 3000;

/**
 * Starts the step as a process of its own: in a session of its own, so the exec that
 * started it can end and it goes on, with what it prints in a file and its exit code
 * in another once it ends. Root owns the directory, so the reviewer cannot write the
 * exit code. Waits until it has written its process id.
 */
const DETACH = `dir=$1; shift
mkdir -p "$dir" && chmod 700 "$dir"
setsid sh -c 'd=$1; shift; echo $$ > "$d/pid"; "$@" > "$d/log" 2>&1; echo $? > "$d/code.tmp"; mv "$d/code.tmp" "$d/code"' step "$dir" "$@" < /dev/null > /dev/null 2>&1 &
i=0
while [ ! -s "$dir/pid" ] && [ "$i" -lt 100 ]; do sleep 0.1; i=$((i + 1)); done
cat "$dir/pid"`;

/**
 * Prints a step's output from a byte on (DETACH), every second what was added, and
 * all of it once the step is over: its exit code is there, or a step killed before it
 * wrote one is gone or a zombie. Not with tail --pid: the step's process is left to
 * PID 1, which is sleep here and never reaps it, and a zombie counts as running.
 * Nothing is killed while it prints, so a slow reader gets every byte.
 */
const FOLLOW = `dir=$1; pid=$2; from=$3
while :; do
  over=""
  if [ -e "$dir/code" ]; then
    over=1
  elif ! stat=$(cat "/proc/$pid/stat" 2> /dev/null); then
    over=1
  else
    set -- \${stat##*) }
    [ "$1" = Z ] && over=1
  fi
  size=$(stat -c %s "$dir/log" 2> /dev/null || echo 0)
  if [ "$size" -ge "$from" ]; then
    tail -c "+$from" "$dir/log" | head -c "$((size - from + 1))"
    from=$((size + 1))
  fi
  [ -n "$over" ] && exit 0
  sleep 1
done`;

/**
 * Stops every process of a step's session, whose id is the step's process id: GNU
 * timeout puts the reviewer in a process group of its own, so the step's group is not
 * all of it. From /proc, so it needs no pkill; a process's name can hold spaces and
 * parentheses, so its fields are read after the last ") ".
 */
const STOP_SESSION = `for p in /proc/[0-9]*; do
  stat=$(cat "$p/stat" 2> /dev/null) || continue
  set -- \${stat##*) }
  [ "$4" = "$0" ] && kill -TERM "\${p#/proc/}" 2> /dev/null
done
exit 0`;

const UNPRIVILEGED = ["setpriv", "--reuid=1000", "--regid=1000", "--clear-groups", "--inh-caps=-all", "--ambient-caps=-all", "--bounding-set=-all", "--no-new-privs"];

/** Run through UNPRIVILEGED: succeeds only with no capabilities left, none to get back, and a root-owned file it cannot write. */
const PROBE = `grep -Eq '^CapEff:[[:space:]]*0+$' /proc/self/status && grep -Eq '^CapBnd:[[:space:]]*0+$' /proc/self/status && grep -Eq '^NoNewPrivs:[[:space:]]*1$' /proc/self/status && ! ( : >> /etc/hostname ) 2> /dev/null`;

/** Kills every process of the reviewer, as root, and fails when one is left. */
const KILL_REVIEWER = `for attempt in $(seq 1 50); do
  pkill -KILL -u 1000 2> /dev/null || true
  pgrep -u 1000 > /dev/null || break
  sleep 0.1
done
! pgrep -u 1000 > /dev/null`;

/**
 * What a warm sandbox is cleared of before the next review starts in it, as root.
 * The reviewer ran without capabilities (UNPRIVILEGED), so it could change only
 * what user 1000 may write, and none of that is trusted:
 * - its processes are killed (again: they were when the last review ended);
 * - of .git only the object store is kept, less every file in it whose ctime is
 *   newer than the mark: that is what the reviewer wrote or changed, and a ctime
 *   cannot be set back. Git fetches a blob that went again when it is read.
 *   Config, hooks, refs and the index are made again by CLONE, and its clean
 *   takes the rest of the working tree but vendor/;
 * - its home goes (the session comes back in the input), and everything it owns
 *   anywhere else but /work. What root owns stays: the tools, and the vendor/
 *   cache of the destination branch.
 * vendor/ stays, for Composer to bring in line with the new lock: reviews are of
 * a team's own pull requests, whose authors decide what is in it anyway. Composer
 * does not restore a file the reviewer edited there, so that is the trade. It is
 * never kept as the cache for other pull requests.
 * Fails when anything is not as a run of ours left it; the review then starts in
 * a fresh sandbox.
 */
const WARM_RESET = `set -e
${KILL_REVIEWER}
[ -f ${REVIEWER_STARTED} ] && [ -d /work/.git/objects ] && [ ! -L /work ] && [ ! -L /work/.git ] && [ ! -L /work/.git/objects ]
find /work/.git/objects -mindepth 1 \\( -type l -o \\( ! -type d -cnewer ${REVIEWER_STARTED} \\) \\) -exec rm -f {} +
rm -rf /work/.git/objects/info
find /work/.git -mindepth 1 -maxdepth 1 ! -name objects -exec rm -rf {} +
if [ -L /work/vendor ] || { [ -e /work/vendor ] && [ ! -d /work/vendor ]; }; then rm -f /work/vendor; fi
find / -xdev \\( -path /proc -o -path /sys -o -path /dev -o -path /work -o -path /home/reviewer \\) -prune -o -user 1000 -print0 | xargs -0r rm -rf --
rm -rf /home/reviewer
mkdir -p /home/reviewer
chown 1000:1000 /home/reviewer
echo "Cleared what the last review could have changed."`;

/** What a warm sandbox must have been started for, to be of use to the next run. */
interface WarmState {
  setupVersion: string | null;
  instance: Instance;
  image: string;
  url: string;
  /**
   * A hash of the keys and the repository's credential it was started with, whose
   * interceptions last as long as the sandbox and cannot be taken back: a run
   * that brings others gets a fresh sandbox, so no key outlives its run.
   */
  credentials: string;
}

/** The longest a sandbox is kept warm, and the shortest that is worth it. */
const WARM_MOST = 3600;
const WARM_LEAST = 60;

/** A ref, branch or remote-tracking name the clone is given: as review-central's Checkout::guard() lets one through. */
const GIT_NAME = /^(?!-)(?!.*\.\.)[^\s\x00-\x1f:^~?*\[\\]{1,1024}$/;

/** Seconds the clone may take, as one git command on review-central's worker. */
const CLONE_SECONDS = 600;

/** A key, the host it is for, and what the sandbox has in its place. */
interface Credential {
  host: string;
  placeholder: string;
  value: string;
}

/** What KeyProxy is started with, for one host. */
interface KeyProxyProps {
  host: string;
  keys: Array<[placeholder: string, value: string]>;
}

/** The headers a provider reads its key from, lower case: the placeholder is swapped in these alone. */
const KEY_HEADERS = ["authorization", "x-api-key", "x-goog-api-key", "api-key"];

/** The query parameter a provider reads its key from, as Gemini's API may. */
const KEY_PARAMETERS = ["key"];

/** Seconds between two samples. */
const SAMPLE_SECONDS = 5;

/**
 * What the sandbox uses, read every few seconds from inside it: a sandbox is a
 * machine of its own, so /proc/stat and /proc/meminfo are its own. First a line
 * with its processors and memory, then one per sample: the time, the processors'
 * busy and stolen time and their total, and the memory in use, in megabytes.
 */
const SAMPLER = `
echo "limits $(nproc) $(awk '/MemTotal/ { print int($2 / 1024) }' /proc/meminfo)"
while :; do
  set -- $(head -n 1 /proc/stat)
  echo "$(date +%s) $(( $2 + $3 + $4 + $7 + $8 )) $9 $(( $2 + $3 + $4 + $5 + $6 + $7 + $8 + $9 )) $(awk '/MemTotal/ { t = $2 } /MemAvailable/ { a = $2 } END { print int((t - a) / 1024) }' /proc/meminfo)"
  sleep ${SAMPLE_SECONDS}
done`;

/** Samples handed back at most: an hour of them; a longer run gets every other one, and so on. */
const MOST_SAMPLES = 720;

/** What the sandbox used: its processors and memory, and per sample the time, processors busy, megabytes in use and the share of time it waited for a processor. */
interface SandboxMetrics {
  processors: number | null;
  memoryMb: number | null;
  samples: Array<[at: number, busy: number, memoryMb: number, stolen: number]>;
}

/** Where the runtime puts the certificate its HTTPS interception signs with. */
const INTERCEPT_CA = "/etc/cloudflare/certs/cloudflare-containers-ca.crt";

/** The system's bundle, which the certificate is added to: OpenSSL's and Rust's default. */
const SYSTEM_CA_BUNDLE = "/etc/ssl/certs/ca-certificates.crt";

/**
 * What the reviewer's home holds that is no session: CLIs and caches, hundreds of
 * megabytes, which are not handed back. As ReviewSessions::CACHES in review-central.
 */
const HOME_CACHES = [".local", ".npm", ".tools", ".cache", ".composer"];

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

/**
 * How long a run goes on without anyone reading it, for a caller whose connection
 * to it was lost to pick it up again with resume().
 */
const READER_GRACE_MS = 2 * 60 * 1000;

/** How much of a run's last events is kept for resume(): far more than is sent while a connection is re-made. */
const EVENTS_KEPT_BYTES = 4 * 1024 * 1024;

/** Six hours is the longest Cloudflare lets an instance sit; a review is done long before. */
const LONGEST = 6 * 60 * 60;

export default {
  async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
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

    // With ?idle=1, only a sandbox that runs nothing, or runs the attempt named
    // in ?attempt: a warm one is the branch's, and a newer review of it may be
    // running there by now.
    if (request.method === "DELETE") {
      return json({ destroyed: url.searchParams.get("idle") === "1" ? await sandbox.cool(url.searchParams.get("attempt")) : (await sandbox.destroy(), true) });
    }

    if (request.method !== "POST") {
      return json({ error: "POST starts a run, DELETE destroys one." }, 405);
    }

    const spec = readSpec(await request.json().catch(() => null));

    if (typeof spec === "string") {
      return json({ error: spec }, 422);
    }

    // Names this run, so a relay of an earlier one in the same sandbox cannot pick up or stop this one.
    const token = crypto.randomUUID();

    return relay(() => env.REVIEW_SANDBOX.getByName(run[1]), token, await sandbox.run(spec, token), ctx);
  },
} satisfies ExportedHandler<Env>;

/** How often a lost connection to a run is tried again, with this many seconds before each. */
const RESUME_WAITS = [1, 2, 5, 10, 20];

/** How often one run is picked up again at most: a connection that keeps dropping is not one to keep making. */
const MOST_RESUMES = 10;

/**
 * Hands a run's events on to review-central, and makes the connection to the run
 * again where it was lost: the call into the Durable Object can drop while the
 * sandbox goes on ("Network connection lost."). review-central reads one stream
 * throughout; the Durable Object sends what it missed again, by number. Only a
 * run that is gone, or that cannot be picked up within RESUME_WAITS, ends the
 * stream without its exit, as before.
 */
function relay(stub: () => DurableObjectStub<ReviewSandbox>, token: string, first: Response, ctx: ExecutionContext): Response {
  if (first.status !== 200 || first.body === null) {
    return first;
  }

  const { readable, writable } = new TransformStream<Uint8Array, Uint8Array>();
  const writer = writable.getWriter();
  const encoder = new TextEncoder();

  ctx.waitUntil(
    (async () => {
      let body: ReadableStream<Uint8Array> | null = first.body;
      let last = 0;
      let ended = false;
      let pending = "";
      let resumes = 0;
      const decoder = new TextDecoder();

      while (body !== null && !ended) {
        try {
          for await (const chunk of body) {
            pending += decoder.decode(chunk, { stream: true });
            let end: number;

            // Whole events only, so a connection made again carries on after the last of them.
            while ((end = pending.indexOf("\n\n")) !== -1) {
              const frame = pending.slice(0, end + 2);
              pending = pending.slice(end + 2);
              const id = /^id: (\d+)$/m.exec(frame);

              if (id !== null) {
                last = Number(id[1]);
              }

              if (/^event: (exit|error)$/m.test(frame)) {
                ended = true;
              }

              try {
                await writer.write(encoder.encode(frame));
              } catch {
                // review-central hung up: on purpose, so the run is stopped now.
                await stub().hangUp(token).catch(() => undefined);

                return;
              }
            }
          }
        } catch (error) {
          console.warn(`The connection to the run was lost after event ${last}: ${error instanceof Error ? error.message : String(error)}`);
        }

        if (ended || ++resumes > MOST_RESUMES) {
          break;
        }

        // Ended without its exit, or lost: picked up again after the last whole event.
        pending = "";
        body = null;

        for (const seconds of RESUME_WAITS) {
          await new Promise((resolve) => setTimeout(resolve, seconds * 1000));
          // A fresh stub each time: one whose call threw cannot be used again.
          const resumed = await stub().resume(token, last).catch((error: unknown) => {
            console.warn(`Could not pick the run up again: ${error instanceof Error ? error.message : String(error)}`);

            return undefined;
          });

          // The run is gone, or what came after `last` is: nothing to carry on with.
          if (resumed === null) {
            console.warn(`The run cannot be picked up again after event ${last}.`);
            break;
          }

          if (resumed !== undefined && resumed.body !== null) {
            console.log(`Picked the run up again after event ${last}.`);
            body = resumed.body;
            break;
          }
        }
      }

      await writer.close().catch(() => undefined);
    })(),
  );

  return new Response(readable, { headers: first.headers });
}

/**
 * The way out of a sandbox to an AI provider: a request to the host it was
 * started for, with a placeholder replaced by its key where a provider reads a
 * key from (KEY_HEADERS, as itself or after "Bearer ", and KEY_PARAMETERS),
 * and only when it is exactly that placeholder. The container names the host it asks for itself, in its Host header, so
 * that is checked: a key goes to its own host over HTTPS, and nowhere else.
 */
export class KeyProxy extends WorkerEntrypoint<Env, KeyProxyProps> {
  async fetch(request: Request): Promise<Response> {
    const { host, keys } = this.ctx.props;
    const url = new URL(request.url);

    if (url.protocol !== "https:" || url.hostname !== host || (url.port !== "" && url.port !== "443")) {
      return new Response(`Only https://${host} is reached this way.`, { status: 403 });
    }

    const real = new Map(keys);
    const swap = (value: string): string => {
      const bearer = /^Bearer (\S+)$/i.exec(value);

      return bearer !== null ? (real.has(bearer[1]) ? `Bearer ${real.get(bearer[1])}` : value) : (real.get(value) ?? value);
    };
    const headers = new Headers();

    for (const [name, value] of request.headers) {
      // The host is the one checked above, from the URL.
      if (name.toLowerCase() !== "host") {
        headers.set(name, KEY_HEADERS.includes(name.toLowerCase()) ? swap(value) : value);
      }
    }

    for (const parameter of KEY_PARAMETERS) {
      const value = url.searchParams.get(parameter);

      if (value !== null && real.has(value)) {
        url.searchParams.set(parameter, real.get(value)!);
      }
    }

    // Not after a redirect: that would take the key to wherever it points.
    return fetch(new Request(url, { method: request.method, headers, body: request.body, redirect: "manual" }));
  }
}

/**
 * The way from a sandbox to the host of the repository it clones. Reading that one
 * repository gets the credential: the two requests git makes to fetch, by GET of
 * info/refs for git-upload-pack and by POST to git-upload-pack. Nothing else gets
 * it, a push (git-receive-pack) least of all, so what the token may do besides
 * reading this repository cannot be done from the sandbox. Every other request to
 * the host goes on as it came, with nothing added: the sandbox has the internet,
 * and the host's other addresses are reached through here too.
 */
export class GitGateway extends WorkerEntrypoint<Env, GitGatewayProps> {
  async fetch(request: Request): Promise<Response> {
    const { host, path, authorization } = this.ctx.props;
    const url = new URL(request.url);

    if (url.protocol !== "https:" || url.hostname !== host || (url.port !== "" && url.port !== "443")) {
      return new Response(`Only https://${host} is reached this way.`, { status: 403 });
    }

    const reads =
      (request.method === "GET" && url.pathname === `${path}/info/refs` && url.searchParams.get("service") === "git-upload-pack" && [...url.searchParams.keys()].length === 1) ||
      (request.method === "POST" && url.pathname === `${path}/git-upload-pack` && url.search === "");
    const headers = new Headers();

    for (const [name, value] of request.headers) {
      if (name.toLowerCase() !== "host") {
        headers.set(name, value);
      }
    }

    if (reads) {
      headers.set("Authorization", authorization);
    }

    // Not after a redirect: that would take the credential to wherever it points.
    return fetch(new Request(url, { method: request.method, headers, body: request.body, redirect: "manual" }));
  }
}

export class ReviewSandbox extends DurableObject<Env> {
  constructor(ctx: DurableObjectState, env: Env) {
    super(ctx, env);

    if (ctx.container === undefined) {
      throw new Error("This Durable Object has no container: check the containers block in wrangler.jsonc.");
    }
  }

  /**
   * One review: a fresh sandbox, the script, and what it left. The answer is a
   * stream of server-sent events, each with the number it has in this run:
   *
   *   id: 12  event: note     data: "a line of this Worker's own"
   *   id: 13  event: output   data: "a piece of what the script printed"
   *   id: 14  event: exit     data: {"exitCode": 0, "seconds": 212, "kept": true, "metrics": {...}}
   *   id: 15  event: error    data: "why there was no run"
   *
   * A piece of output may end anywhere, so it is appended, not read as a line.
   *
   * The run does not hang on the stream. The connection to this Durable Object can
   * be lost while the sandbox goes on, so the run keeps the events it sent last and
   * waits READER_GRACE_MS for resume() to pick them up again. Only a run nobody
   * comes back for in that time is stopped, and hangUp() stops one at once.
   */
  async run(spec: RunSpec, token: string | null = null): Promise<Response> {
    // A warm sandbox serves one run at a time; the caller starts another of its own.
    if (this.busy) {
      return json({ error: "This sandbox is running a review." }, 409);
    }

    this.busy = true;
    this.attempt = spec.attempt ?? null;
    this.token = token;
    this.events = [];
    this.eventBytes = 0;
    this.sequence = 0;
    this.finished = false;
    this.abandoned = false;
    await this.ctx.storage.deleteAlarm();

    const readable = this.attach();

    const send = async (event: string, data: unknown): Promise<void> => {
      const frame = `id: ${++this.sequence}\nevent: ${event}\ndata: ${JSON.stringify(data)}\n\n`;
      this.remember(this.sequence, frame);
      await this.deliver(frame);
    };

    // A line that says nothing, so a proxy on the way does not take the silence of
    // a reviewer that is thinking for a dead connection; and the moment to give up
    // on a run whose reader did not come back.
    const alive = setInterval(() => {
      if (this.reader !== null) {
        void this.deliver(": keep-alive\n\n");
      } else if (!this.abandoned && this.lostAt !== null && Date.now() - this.lostAt > READER_GRACE_MS) {
        console.warn(`Nobody came back for the run in ${READER_GRACE_MS / 1000} seconds: stopping it.`);
        this.abandon();
      }
    }, 5000);

    this.ctx.waitUntil(
      this.review(spec, send, () => !this.abandoned)
        .catch(async (error: unknown) => {
          console.error("The run failed:", error instanceof Error ? error.message : String(error));
          await send("error", error instanceof Error ? error.message : String(error));

          return false;
        })
        .then(async (warmable) => {
          clearInterval(alive);

          // Kept for the next review of the branch only after a run that went as it
          // should, in a sandbox that kept the reviewer from changing what root
          // owns; otherwise, and without warm, it does not outlive its review.
          if (warmable && spec.warm && !this.abandoned) {
            await this.keepWarm(spec.warm.seconds).catch(() => this.destroy());
          } else {
            await this.destroy();
          }
        })
        .finally(async () => {
          this.busy = false;
          this.attempt = null;
          this.finished = true;
          const reader = this.reader;
          this.reader = null;
          await reader?.close().catch(() => undefined);
        }),
    );

    return new Response(readable, {
      headers: { "Content-Type": "text/event-stream", "Cache-Control": "no-cache" },
    });
  }

  /**
   * The events of this run after the one numbered `after`, and then the rest as
   * they come: for a caller whose connection to the run was lost. Null when
   * there is no such run here any more, or the events after `after` are no longer
   * kept, so what the caller has cannot be continued.
   */
  async resume(token: string, after: number): Promise<Response | null> {
    // Another run's relay, of one that ended here before this one started.
    if (token !== this.token || (!this.busy && !this.finished) || this.abandoned) {
      return null;
    }

    const missed = this.events.filter((event) => event.id > after);

    // What was sent next is no longer kept: continuing would leave a gap.
    if (after < this.sequence && (missed.length === 0 || missed[0].id !== after + 1)) {
      return null;
    }

    console.log(`Picked up again after event ${after}, ${missed.length} event(s) sent again.`);

    const readable = this.attach();
    const writer = this.reader!;

    for (const event of missed) {
      void writer.write(this.encoder.encode(event.frame)).catch(() => this.lose(writer));
    }

    // Ended while nobody read: what is left to say is said, and that is all.
    if (this.finished) {
      this.reader = null;
      void writer.close().catch(() => undefined);
    }

    return new Response(readable, {
      headers: { "Content-Type": "text/event-stream", "Cache-Control": "no-cache" },
    });
  }

  /** The caller let the run go on purpose: stop it now rather than wait for it to come back. */
  async hangUp(token: string): Promise<void> {
    if (token === this.token && this.busy && !this.abandoned) {
      console.log("The caller hung up: stopping the run.");
      this.abandon();
    }
  }

  /** Whether a run is going here, and the attempt it is. */
  private busy = false;

  private attempt: string | null = null;

  /** What names the run here, or the last one, for resume() and hangUp(); null for one started without. */
  private token: string | null = null;

  /** Whether the last run here has ended, its last events still kept for a caller that lost them. */
  private finished = false;

  /** Whether the run here was let go: nobody came back for it, or the caller hung up. */
  private abandoned = false;

  /** Where the run's events go now; null while nobody reads. */
  private reader: WritableStreamDefaultWriter<Uint8Array> | null = null;

  /** When the last reader was lost. */
  private lostAt: number | null = null;

  /** The run's last events, by number, for resume(). */
  private events: { id: number; frame: string }[] = [];

  private eventBytes = 0;

  private sequence = 0;

  /** What the script runs now, stopped when the run is let go. */
  private current: ExecProcess | null = null;
  /** The session of a step started with DETACH (its process id), which killing its follower does not stop. */
  private group: string | null = null;

  private readonly encoder = new TextEncoder();

  /** A new reader for the run's events, in place of one that was lost. */
  private attach(): ReadableStream<Uint8Array> {
    const { readable, writable } = new TransformStream<Uint8Array, Uint8Array>();
    const old = this.reader;
    this.reader = writable.getWriter();
    this.lostAt = null;
    void old?.close().catch(() => undefined);

    return readable;
  }

  private async deliver(frame: string): Promise<void> {
    const writer = this.reader;

    if (writer === null) {
      return;
    }

    try {
      await writer.write(this.encoder.encode(frame));
    } catch {
      this.lose(writer);
    }
  }

  /** Nobody reads from this writer any more. Its run goes on, for a while, for resume(). */
  private lose(writer: WritableStreamDefaultWriter<Uint8Array>): void {
    if (this.reader !== writer) {
      return;
    }

    console.warn(`Lost the reader after event ${this.sequence}; the run goes on for ${READER_GRACE_MS / 1000} seconds for it to come back.`);
    this.reader = null;
    this.lostAt = Date.now();
  }

  /** Keeps an event for resume(), letting the oldest go past EVENTS_KEPT_BYTES. */
  private remember(id: number, frame: string): void {
    this.events.push({ id, frame });
    this.eventBytes += frame.length;

    while (this.eventBytes > EVENTS_KEPT_BYTES && this.events.length > 1) {
      this.eventBytes -= this.events.shift()!.frame.length;
    }
  }

  private abandon(): void {
    this.abandoned = true;
    this.current?.kill();

    if (this.group !== null) {
      void this.ctx.container?.exec(["sh", "-c", STOP_SESSION, this.group], { stdout: "ignore", stderr: "ignore" }).catch(() => undefined);
    }
  }

  /**
   * Leaves the sandbox running for the next review, and has it destroyed when
   * none came: by the alarm, and by the runtime after the same time with nothing
   * asked of it, should the alarm not fire.
   */
  private async keepWarm(seconds: number): Promise<void> {
    // Nothing the reviewer started goes on while the sandbox waits, calling a
    // provider through the keys' interception or changing files.
    await this.must(["sh", "-c", KILL_REVIEWER], "stop the reviewer's processes");
    await this.ctx.container!.setInactivityTimeout((seconds + 120) * 1000);
    await this.ctx.storage.setAlarm(Date.now() + seconds * 1000);
  }

  /** Destroys the sandbox unless a run other than this attempt is going in it; says whether it did. */
  async cool(attempt: string | null = null): Promise<boolean> {
    if (this.busy && (attempt === null || attempt !== this.attempt)) {
      return false;
    }

    await this.destroy();

    return true;
  }

  /** The time a warm sandbox was kept for is up. */
  async alarm(): Promise<void> {
    if (!this.busy) {
      await this.destroy();
    }
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

  /**
   * Hands the sandbox's HTTPS to each key's host to KeyProxy, with that host's
   * keys, and to the host of a repository it clones with a credential to
   * GitGateway. Before every start: an interception lasts as long as its sandbox.
   */
  private async intercept(credentials: Credential[], clone: CloneSpec | null | undefined): Promise<void> {
    const container = this.ctx.container!;
    const exports = this.ctx.exports as unknown as {
      KeyProxy: (options: { props: KeyProxyProps }) => Fetcher;
      GitGateway: (options: { props: GitGatewayProps }) => Fetcher;
    };

    if (clone?.authorization) {
      const url = new URL(clone.url);
      await container.interceptOutboundHttps(url.hostname, exports.GitGateway({ props: { host: url.hostname, path: url.pathname, authorization: clone.authorization } }));
    }

    const hosts = new Map<string, Array<[string, string]>>();

    for (const credential of credentials) {
      hosts.set(credential.host, [...(hosts.get(credential.host) ?? []), [credential.placeholder, credential.value]]);
    }

    for (const [host, keys] of hosts) {
      await container.interceptOutboundHttps(host, exports.KeyProxy({ props: { host, keys } }));
    }
  }

  async destroy(): Promise<void> {
    const container = this.ctx.container;

    // What it was started for goes with it: the next run starts afresh.
    await this.ctx.storage.delete("warm").catch(() => undefined);
    await this.ctx.storage.deleteAlarm().catch(() => undefined);

    if (container?.running) {
      await container.destroy().catch(() => undefined);
    }
  }

  /**
   * Whether the sandbox still running here was started for what this run needs:
   * the same tools, size, image and repository. Then it can be cleared and used.
   */
  private async warmFor(spec: RunSpec, image: string): Promise<boolean> {
    if (!spec.warm || !spec.clone || !this.ctx.container!.running) {
      return false;
    }

    const state = await this.ctx.storage.get<WarmState>("warm");

    return (
      state !== undefined &&
      state.setupVersion === spec.setupVersion &&
      state.instance === spec.instance &&
      state.image === image &&
      state.url === spec.clone.url &&
      state.credentials === (await credentialsHash(spec))
    );
  }

  /** Resolves true when the run went as it should, whatever the script's exit code: then a warm sandbox may be kept. */
  private async review(spec: RunSpec, send: (event: string, data: unknown) => Promise<void>, wanted: () => boolean): Promise<boolean> {
    const container = this.ctx.container!;
    // An image wrangler.jsonc builds, one named in the settings, or Cloudflare's own.
    const image = container.images.reviewer ?? this.env.IMAGE ?? MANAGED_IMAGE;
    // The image's own command may end at once, and a sandbox stops with it.
    const always = { entrypoint: ["sleep", "infinity"], enableInternet: true, instance: spec.instance };
    const snapshotKey = spec.setup !== null && spec.setupVersion !== null ? `review/snapshots/${spec.setupVersion}.json` : null;

    const started = Date.now();
    await send("note", "##[phase] start");

    // The sandbox the last review of this branch left, cleared of what that
    // reviewer could have changed. It was started with the same keys and
    // credential (warmFor), whose interceptions are still in place.
    let warm = false;

    if (await this.warmFor(spec, image)) {
      try {
        await this.must(["sh", "-c", WARM_RESET], "clear the warm sandbox");
        warm = true;
        await send("note", "The sandbox of the last review of this branch was still warm: cleared, and used again.");
      } catch (error) {
        await send("note", `The warm sandbox could not be used again (${error instanceof Error ? error.message.slice(0, 300) : String(error)}); starting a fresh one.`);
      }
    }

    if (warm) {
      return this.inSandbox(spec, send, wanted, started, true, false);
    }

    // One left by a run that never ended, or a warm one of no use, is not this review's to continue.
    await this.destroy();

    // The reviewer installs the repository's packages and calls its AI provider,
    // so it has the internet. It gets no credential of the repository's host: a
    // clone with one goes through GitGateway, which keeps it.
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
        await this.intercept(spec.credentials, spec.clone);
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
      await this.intercept(spec.credentials, spec.clone);
      container.start({ ...always, image });
      await this.ready();
      await send("note", `Sandbox started (${spec.instance}, ${image}).`);
    }

    // What it was started for, so the next run of the branch can tell it is of use.
    if (spec.warm && spec.clone) {
      await this.ctx.storage.put("warm", { setupVersion: spec.setupVersion, instance: spec.instance, image, url: spec.clone.url, credentials: await credentialsHash(spec) } satisfies WarmState);
    }

    return this.inSandbox(spec, send, wanted, started, false, prepared);
  }

  /**
   * The run in a sandbox that is there: a fresh one, or a warm one that was
   * cleared. Without a snapshot, a fresh one installs the tools first.
   */
  private async inSandbox(spec: RunSpec, send: (event: string, data: unknown) => Promise<void>, wanted: () => boolean, started: number, warm: boolean, prepared: boolean): Promise<boolean> {
    const container = this.ctx.container!;

    await container.setInactivityTimeout(Math.min(spec.timeout + 900, LONGEST) * 1000);

    // What it uses, for as long as the review runs: never in the way of it.
    const metrics: SandboxMetrics = { processors: null, memoryMb: null, samples: [] };
    const sampler = await container.exec(["sh", "-c", SAMPLER], { stdout: "pipe", stderr: "ignore" }).catch(() => null);
    const sampling = sampler === null ? Promise.resolve() : this.sample(sampler, metrics).catch(() => undefined);

    const decoder = new TextDecoder();

    /** Runs a command and streams what it prints; resolves with its exit code, or 143 when nobody reads any more. */
    const streamed = async (command: string[], options: ContainerExecOptions): Promise<number> => {
      const process = await container.exec(command, { cwd: "/", ...options, stderr: "combined" });
      this.current = process;

      try {
        if (!wanted()) {
          process.kill();

          return 143;
        }

        if (process.stdout !== null) {
          for await (const chunk of process.stdout as ReadableStream<Uint8Array>) {
            await send("output", decoder.decode(chunk, { stream: true }));

            if (!wanted()) {
              process.kill();

              return 143;
            }
          }
        }

        return await process.exitCode;
      } finally {
        this.current = null;
      }
    };

    /** The text a short command prints, or null when it fails. */
    const read = async (command: string[], options: ContainerExecOptions = {}): Promise<string | null> => {
      const process = await container.exec(command, { ...options, stdout: "pipe", stderr: "ignore" });
      const text = process.stdout === null ? "" : await new Response(process.stdout as ReadableStream<Uint8Array>).text();

      return (await process.exitCode) === 0 ? text : null;
    };

    /**
     * Runs a long command as a process of its own (DETACH) and streams what it prints
     * by following its output file. The connection to an exec can be lost while the
     * sandbox goes on ("Network connection lost"): then the file is followed again from
     * the byte it got to, and the step is not lost with the connection.
     */
    const followed = async (command: string[], options: ContainerExecOptions): Promise<number> => {
      // Root's own, where the step runs through setpriv; where it runs as the reviewer's
      // user it has to be able to write it, as it can its exit code there too.
      const dir = `${options.user === undefined ? "/var/lib/review-steps" : "/tmp/review-steps"}/${crypto.randomUUID()}`;
      // Started with the step's directory and environment, which its process keeps.
      const pid = (await read(["sh", "-c", DETACH, "detach", dir, ...command], options))?.trim() ?? "";

      if (!/^\d+$/.test(pid)) {
        throw new Error("The step did not start.");
      }

      this.group = pid;
      let offset = 0;
      let lost = 0;

      try {
        for (;;) {
          if (!wanted()) {
            await container.exec(["sh", "-c", STOP_SESSION, pid], { stdout: "ignore", stderr: "ignore" }).catch(() => undefined);

            return 143;
          }

          try {
            // From the next byte; ends once the step is over and all it printed is read.
            const tail = await container.exec(["sh", "-c", FOLLOW, "follow", dir, pid, String(offset + 1)], { stdout: "pipe", stderr: "ignore" });
            this.current = tail;

            if (tail.stdout !== null) {
              for await (const chunk of tail.stdout as ReadableStream<Uint8Array>) {
                offset += chunk.byteLength;
                await send("output", decoder.decode(chunk, { stream: true }));

                if (!wanted()) {
                  break;
                }
              }
            }

            if (!wanted()) {
              continue;
            }

            await tail.exitCode;
            const code = (await read(["cat", `${dir}/code`]))?.trim() ?? "";

            // No exit code: the step was killed before it could write one.
            return /^\d+$/.test(code) ? Number(code) : 143;
          } catch (error) {
            if (++lost > MOST_REATTACHES) {
              throw error;
            }

            await send("note", `The connection to the step was lost (${error instanceof Error ? error.message : String(error)}); it goes on in the sandbox, and its output is followed again from byte ${offset}.`);
            // In short steps, so a run that is stopped meanwhile does not wait out the rest.
            for (let waited = 0; waited < REATTACH_WAIT_MS * lost && wanted(); waited += 500) {
              await new Promise((resolve) => setTimeout(resolve, 500));
            }
          } finally {
            this.current = null;
          }
        }
      } finally {
        this.group = null;
      }
    };

    // No snapshot to be had, and a setup to run: installed here, for this review
    // alone, as a last resort.
    if (!warm && !prepared && spec.setup !== null) {
      const installed = await streamed(["sh", "-c", spec.setup], { env: { DEBIAN_FRONTEND: "noninteractive", HOME: "/root" } });

      if (installed !== 0) {
        await send("exit", { exitCode: installed, seconds: Math.round((Date.now() - started) / 1000), kept: false });

        return false;
      }
    }

    const gateway = spec.clone?.authorization ? new URL(spec.clone.url).hostname : null;
    const intercepted = spec.credentials.length > 0 || gateway !== null;

    // Whether the reviewer can be kept from what root owns here (UNPRIVILEGED):
    // only then is the sandbox kept for another review. Without warm, the reviewer
    // runs as it always has.
    const unprivileged = spec.warm ? await this.succeeds([...UNPRIVILEGED, "sh", "-c", PROBE]) : false;
    const asReviewer = (command: string[]): string[] => (unprivileged ? [...UNPRIVILEGED, ...command] : command);
    const reviewer: ContainerExecOptions = unprivileged ? {} : { user: REVIEWER };

    if (spec.warm && !unprivileged) {
      await send("note", "The reviewer cannot be kept from changing the sandbox's tools here, so the sandbox is not kept for the next review.");
    }

    // The certificate the keys' hosts and the repository's are answered with from
    // here, which every client in the sandbox has to trust: the system's, and
    // Node's through NODE_EXTRA_CA_CERTS below. Root's work, before the reviewer runs.
    if (intercepted) {
      await this.must(
        ["sh", "-c", `test -s ${INTERCEPT_CA} && cp ${INTERCEPT_CA} /usr/local/share/ca-certificates/cloudflare-containers-ca.crt && update-ca-certificates > /dev/null`],
        "trust the certificate the intercepted hosts are answered with",
      );
    }

    if (spec.credentials.length > 0) {
      await send("note", `The AI providers' keys stay in the Worker: ${[...new Set(spec.credentials.map((credential) => credential.host))].join(", ")} reached through it.`);
    }

    // The sandbox clones the repository itself; what is unpacked next goes over it.
    if (spec.clone) {
      // In a warm sandbox the same script fetches what is new over what is there.
      await send("note", warm ? "##[phase] fetch" : "##[phase] clone");

      if (gateway !== null) {
        await send("note", `The repository's credential stays in the Worker: ${gateway} reached through it, which adds it to reading this repository only.`);
      }

      await this.must(["sh", "-c", `mkdir -p /work /home/reviewer && chown ${REVIEWER} /work /home/reviewer`], "make the work directory");
      const { url, ref, name, commit, destination, base, since } = spec.clone;
      // A host that stops answering does not hold the sandbox for the review's whole time.
      const cloned = await streamed(asReviewer(["timeout", "--signal=TERM", "--kill-after=30", String(CLONE_SECONDS), "sh", "-c", CLONE, "clone", url, ref, name, commit, destination ?? "", base ?? "", since ?? ""]), {
        cwd: "/work",
        // As review-central's own git: no LFS download, no local repository as a remote.
        env: {
          HOME: "/home/reviewer",
          GIT_TERMINAL_PROMPT: "0",
          GIT_LFS_SKIP_SMUDGE: "1",
          GIT_CONFIG_COUNT: "1",
          GIT_CONFIG_KEY_0: "protocol.file.allow",
          GIT_CONFIG_VALUE_0: "never",
          ...(gateway !== null ? { GIT_SSL_CAINFO: SYSTEM_CA_BUNDLE } : {}),
        },
        ...reviewer,
      });

      // No review ran: an error, as a checkout on the worker that failed is, not
      // a reviewer that failed.
      if (cloned !== 0) {
        throw new Error(`The sandbox could not clone the repository (exit ${cloned}).`);
      }
    }

    await send("note", "##[phase] unpack");
    const input = await this.env.BUCKET.get(spec.input);

    if (input === null) {
      throw new Error(`Nothing in the bucket at ${spec.input}.`);
    }

    // Straight from the bucket into tar: tar and gzip are in any image, so the
    // sandbox needs nothing of ours to be handed its files. Over a clone, the
    // scripts' place is made the review's own first, whatever the clone has there.
    await this.must(
      [
        "sh",
        "-c",
        "mkdir -p /work /home/reviewer && for place in /work/.review /work/.review/skill; do if [ -L \"$place\" ]; then rm -f \"$place\"; fi; done && " +
          `tar -xzf - -C / --no-same-owner && chown -R ${REVIEWER} /work /home/reviewer`,
      ],
      "unpack the checkout",
      input.body,
    );

    const cache = spec.cache && CACHE_KEY.test(spec.cache.key) && CACHE_FILE.test(spec.cache.file) ? spec.cache : null;
    let restored = false;
    // The vendor/ the last review of the branch installed, which Composer brings
    // in line with this commit's lock (WARM_RESET says why it stays).
    const keptVendor = warm && (await this.succeeds(["sh", "-c", "[ -d /work/vendor ] && [ ! -L /work/vendor ]"]));

    if (keptVendor) {
      await send("note", "vendor/ of the last review of this branch is still there; Composer brings it in line with this commit.");
    } else if (cache !== null) {
      await send("note", "##[phase] vendor");

      // A warm sandbox still has the destination branch's vendor/ that root put
      // there, which the reviewer could not change: no need to fetch it again.
      if (warm && (await this.succeeds(["sh", "-c", '[ -f "$0" ] && [ ! -L "$0" ] && [ "$(stat -c %u "$0")" = 0 ]', cache.file]))) {
        restored = true;
        await send("note", "The destination branch's vendor/ is still in the sandbox.");
      } else {
        restored = await this.restoreCache(cache).catch(async (error: unknown) => {
          await send("note", `Could not fetch vendor/ from the bucket, so Composer installs it: ${error instanceof Error ? error.message : String(error)}`);

          return false;
        });

        if (restored) {
          await send("note", "Fetched vendor/ for this composer.lock from the bucket.");
        }
      }
    }

    // From here on, what changes is the reviewer's doing: WARM_RESET reads this mark.
    if (spec.warm) {
      await this.must(["sh", "-c", `mkdir -p "$(dirname ${REVIEWER_STARTED})" && touch ${REVIEWER_STARTED}`], "mark the reviewer's start");
    }

    // Followed, not streamed: the review runs for minutes, and outlives a lost connection.
    const exitCode = await followed(
      asReviewer(["timeout", "--signal=TERM", "--kill-after=30", String(spec.timeout), "sh", "-c", spec.script]),
      {
        cwd: "/work",
        env: {
          ...spec.env,
          ...(intercepted ? { NODE_EXTRA_CA_CERTS: INTERCEPT_CA, SSL_CERT_FILE: SYSTEM_CA_BUNDLE } : {}),
          // prepare-review.sh then keeps vendor/ as it is, and never stores it for others.
          ...(keptVendor ? { REVIEW_WARM_VENDOR: "true" } : {}),
          HOME: "/home/reviewer",
        },
        ...reviewer,
      },
    );
    let kept = false;

    // What the reviewer left, also when it failed: a session that was resumed and
    // a report that is half there are both the caller's to judge.
    if (wanted()) {
      await send("note", "##[phase] keep");
      kept = await this.keep(spec).catch(async (error: unknown) => {
        await send("note", `Could not hand back the reviewer's files: ${error instanceof Error ? error.message : String(error)}`);

        return false;
      });
    }

    // A vendor/ of the destination branch the script kept beside the one it was
    // given. Never in the way of the review: without it the next one installs again.
    // Never from a warm sandbox: the reviewer before could write there, and this
    // one goes to the reviews of other pull requests.
    if (cache !== null && !warm && wanted()) {
      await this.storeCache(cache)
        .then((stored) => (stored ? send("note", "Kept the destination branch's vendor/ in the bucket for the next review of this repository.") : undefined))
        .catch((error: unknown) => send("note", `Could not keep vendor/ in the bucket: ${error instanceof Error ? error.message : String(error)}`));
    }

    sampler?.kill();
    await sampling;

    const keptWarm = spec.warm !== null && spec.warm !== undefined && unprivileged && wanted();

    if (keptWarm) {
      await send("note", `The sandbox stays warm for ${Math.round(spec.warm!.seconds / 60)} minutes, for a next review of this branch.`);
    }

    await send("exit", { exitCode, seconds: Math.round((Date.now() - started) / 1000), kept, warm, keptWarm, metrics: thinned(metrics) });

    return keptWarm;
  }

  /** Whether a command, as root, ends well. */
  private async succeeds(command: string[]): Promise<boolean> {
    const process = await this.ctx.container!.exec(command, { stdout: "ignore", stderr: "ignore" });

    return (await process.exitCode) === 0;
  }

  /**
   * Reads the sampler's lines into the metrics: per sample, the processors kept
   * busy since the one before, and the share of that time a processor was not
   * there for it (stolen: a sandbox on a part of a processor waits for the rest).
   */
  private async sample(process: ExecProcess, metrics: SandboxMetrics): Promise<void> {
    const decoder = new TextDecoder();
    let pending = "";
    let before: [busy: number, stolen: number, total: number] | null = null;

    for await (const chunk of process.stdout as ReadableStream<Uint8Array>) {
      pending += decoder.decode(chunk, { stream: true });
      const lines = pending.split("\n");
      pending = lines.pop() ?? "";

      for (const line of lines) {
        const parts = line.trim().split(/\s+/);

        if (parts[0] === "limits") {
          metrics.processors = Number(parts[1]) || null;
          metrics.memoryMb = Number(parts[2]) || null;
          continue;
        }

        const [at, busy, stolen, total, memory] = parts.map(Number);

        if (parts.length !== 5 || [at, busy, stolen, total, memory].some((value) => !Number.isFinite(value))) {
          continue;
        }

        if (before !== null && total > before[2]) {
          const elapsed = total - before[2];
          metrics.samples.push([
            at,
            Math.round(((busy - before[0]) / elapsed) * (metrics.processors ?? 1) * 100) / 100,
            memory,
            Math.round(((stolen - before[1]) / elapsed) * 100) / 100,
          ]);
        }

        before = [busy, stolen, total];
      }
    }
  }

  /**
   * Puts the repository's vendor/ archive where the script looks for it, when the
   * bucket has one. The directory is there either way, for Composer's own cache.
   */
  private async restoreCache(cache: { key: string; file: string }): Promise<boolean> {
    // Root's, and sticky: the reviewer writes Composer's cache and the new archive
    // there, but cannot change or replace the archive root put in place, which a
    // warm sandbox hands the next review.
    await this.must(["sh", "-c", 'mkdir -p /cache/composer && chown 0:0 /cache/composer && chmod 1777 /cache/composer && rm -f "$0"', cache.file], "make the cache directory");

    const object = await this.env.BUCKET.get(cache.key);

    if (object === null) {
      return false;
    }

    await this.must(["sh", "-c", 'cat > "$0" && chmod 0644 "$0"', cache.file], "put vendor/ in place", object.body);

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
          `cd / && tar -czf /tmp/review-output.tar.gz --ignore-failed-read ${HOME_CACHES.map((cache) => `--exclude=home/reviewer/${cache}`).join(" ")} home/reviewer $kept`,
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

  const credentials = Array.isArray(spec.credentials) ? spec.credentials : [];

  if (
    credentials.length > 32 ||
    credentials.some(
      (credential) =>
        typeof credential !== "object" ||
        credential === null ||
        typeof credential.host !== "string" ||
        !/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/.test(credential.host) ||
        typeof credential.placeholder !== "string" ||
        !/^[A-Za-z0-9_-]{24,200}$/.test(credential.placeholder) ||
        typeof credential.value !== "string" ||
        credential.value === "",
    )
  ) {
    return "credentials name a host, a placeholder and a key each.";
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

  // The vendor/ cache, checked against CACHE_KEY and CACHE_FILE where it is used.
  const cache = typeof spec.cache === "object" && spec.cache !== null ? (spec.cache as Record<string, unknown>) : null;

  if (cache !== null && (typeof cache.key !== "string" || typeof cache.file !== "string")) {
    return "cache names a key and a file, or is left out.";
  }

  const clone = readClone(spec.clone, credentials as Credential[]);

  if (typeof clone === "string") {
    return clone;
  }

  const warmSeconds = typeof spec.warm === "object" && spec.warm !== null ? Number((spec.warm as Record<string, unknown>).seconds) : null;

  if (warmSeconds !== null && (!Number.isInteger(warmSeconds) || warmSeconds < WARM_LEAST || warmSeconds > WARM_MOST || clone === null)) {
    return `warm keeps a sandbox that clones for ${WARM_LEAST} to ${WARM_MOST} seconds, or is left out.`;
  }

  return {
    input: spec.input,
    output: spec.output,
    setup: typeof spec.setup === "string" && spec.setup !== "" ? spec.setup : null,
    setupVersion: typeof spec.setupVersion === "string" ? spec.setupVersion : null,
    script: spec.script,
    env: env as Record<string, string>,
    credentials: credentials as Credential[],
    keep: keep as string[],
    timeout,
    instance,
    cache: cache === null ? null : { key: cache.key as string, file: cache.file as string },
    clone,
    warm: warmSeconds === null ? null : { seconds: warmSeconds },
    attempt: typeof spec.attempt === "string" && /^[A-Za-z0-9._-]{1,100}$/.test(spec.attempt) ? spec.attempt : null,
  };
}

/**
 * The clone that was asked for, null for none, or what is wrong with it. A
 * credential goes only to a host no AI key is for: one interception a host.
 */
function readClone(value: unknown, credentials: Credential[]): CloneSpec | null | string {
  if (value === undefined || value === null) {
    return null;
  }

  const wrong = "clone names an https URL, a ref, a name and a commit, and may name a destination, a base and an authorization.";

  if (typeof value !== "object") {
    return wrong;
  }

  const clone = value as Record<string, unknown>;
  const name = (part: unknown): part is string => typeof part === "string" && GIT_NAME.test(part);
  const hash = (part: unknown): part is string => typeof part === "string" && /^[0-9a-f]{7,40}$/.test(part);
  const url = typeof clone.url === "string" ? URL.parse(clone.url) : null;
  const authorization = clone.authorization ?? null;

  if (
    url === null ||
    url.protocol !== "https:" ||
    url.username !== "" ||
    url.password !== "" ||
    url.search !== "" ||
    url.hash !== "" ||
    !name(clone.ref) ||
    !name(clone.name) ||
    !hash(clone.commit) ||
    (clone.destination !== undefined && clone.destination !== null && !name(clone.destination)) ||
    (clone.base !== undefined && clone.base !== null && (!hash(clone.base) || !name(clone.destination))) ||
    (clone.since !== undefined && clone.since !== null && !hash(clone.since)) ||
    (authorization !== null && (typeof authorization !== "string" || authorization === "" || /[\r\n]/.test(authorization)))
  ) {
    return wrong;
  }

  if (authorization !== null && ((url.port !== "" && url.port !== "443") || credentials.some((credential) => credential.host === url.hostname))) {
    return "A clone with a credential reaches its host on port 443, and not a host an AI key is for.";
  }

  return {
    url: url.toString(),
    authorization: authorization as string | null,
    ref: clone.ref,
    name: clone.name,
    commit: clone.commit,
    destination: (clone.destination as string | null | undefined) ?? null,
    base: (clone.base as string | null | undefined) ?? null,
    since: (clone.since as string | null | undefined) ?? null,
  };
}

/** A hash of the keys and the repository's credential a run brings, for a warm sandbox to compare with what it was started with. */
async function credentialsHash(spec: RunSpec): Promise<string> {
  const given = JSON.stringify([spec.credentials.map((credential) => [credential.host, credential.placeholder, credential.value]), spec.clone?.authorization ?? null]);
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(given));

  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, "0")).join("");
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

/**
 * The metrics with at most MOST_SAMPLES samples: of a longer run, every other
 * one, or every third, and so on.
 */
function thinned(metrics: SandboxMetrics): SandboxMetrics {
  const every = Math.ceil(metrics.samples.length / MOST_SAMPLES);

  return every <= 1 ? metrics : { ...metrics, samples: metrics.samples.filter((_, index) => index % every === 0) };
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}
