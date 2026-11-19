/**
 * The review's report as a tool, for Pi: `submit_review`.
 *
 * Pi cannot be held to a schema for its answer, but it holds a tool's arguments to
 * one. So the report is the argument of a tool: Pi checks it against
 * ai-review.schema.json, the schema every reviewer's report has, and sends it back
 * to the model when it does not fit. A report that fits is written to
 * ai-review.json, and the run ends there, without another turn.
 *
 * Loaded by run-pi.sh with `-e`, the one extension it allows. It reads the schema
 * from beside itself and writes one file, named by the script.
 */
import { readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { defineTool, type ExtensionAPI } from "@earendil-works/pi-coding-agent";

const here = dirname(fileURLToPath(import.meta.url));
const schema = JSON.parse(readFileSync(join(here, "ai-review.schema.json"), "utf8"));

// What a JSON Schema document says about itself is no part of a tool's arguments.
delete schema.$schema;
delete schema.$id;
delete schema.title;

const submitReview = defineTool({
	name: "submit_review",
	label: "Submit review",
	description:
		"Hand in the review's report. Call this once, as your last action, with the whole report as its arguments. The review is over when it is accepted.",
	promptSnippet: "Hand in the review's report as a terminating tool call",
	promptGuidelines: [
		"When the review is done, call submit_review with the report. Do not write ai-review.json yourself.",
		"If submit_review refuses the report, fix what it names and call it again.",
		"After submit_review accepts the report, say nothing more.",
	],
	parameters: schema,

	async execute(_toolCallId, params) {
		writeFileSync(process.env.REVIEW_REPORT_FILE ?? "ai-review.json", `${JSON.stringify(params, null, 2)}\n`);

		const findings = Array.isArray((params as { findings?: unknown[] }).findings) ? (params as { findings: unknown[] }).findings.length : 0;

		return {
			content: [{ type: "text", text: `The report is in: ${findings} finding(s).` }],
			details: { findings },
			terminate: true,
		};
	},
});

export default function (pi: ExtensionAPI) {
	pi.registerTool(submitReview);
}
