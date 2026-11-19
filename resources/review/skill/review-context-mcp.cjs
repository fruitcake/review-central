#!/usr/bin/env node
// The pull request's context as an MCP server, for the OpenCodeReview step.
//
// OCR's prompt is fixed and its background is capped at 8000 characters, so a long
// ticket or a long thread reaches it shortened. This gives its review agent the
// whole of each on request, as tools:
//
//   read_ticket    the ticket the pull request belongs to, from review-ticket.md
//   read_comments  what people said on the pull request, optionally on one file,
//                  from review-context.json
//   read_rules     the repository's own rules - CLAUDE.md, AGENTS.md, REVIEW.md
//                  and .ai/review-conventions.md, and a CLAUDE.md or AGENTS.md in
//                  a subdirectory for the files under it - as an outline, or the
//                  sections that mention a word
//
// Every tool reads a fixed file and takes no path, so nothing a pull request says can
// make it read anything else. The rules come from the destination branch rather than
// from the pull request's own checkout: a pull request must not be able to rewrite the
// rules it is reviewed against, the same reason OCR's own Bitbucket example reads its
// rule.json from the destination branch.
//
// Stdio transport, one JSON-RPC message per line. No dependencies: the review image
// has Node, and nothing has to be installed for this.
//
// Configured by run-ocr.sh, which passes the file locations and the destination
// branch in the environment:
//
//   REVIEW_CONTEXT_FILE  review-context.json
//   REVIEW_TICKET_FILE   review-ticket.md
//   REVIEW_RULES_REF     the ref to read the rules from, e.g. origin/main; the
//                        working tree when unset
//   REVIEW_REPO          the repository root, the current directory by default

'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const REPO = process.env.REVIEW_REPO || process.cwd();
const CONTEXT_FILE = path.resolve(REPO, process.env.REVIEW_CONTEXT_FILE || 'review-context.json');
const TICKET_FILE = path.resolve(REPO, process.env.REVIEW_TICKET_FILE || 'review-ticket.md');
const RULES_REF = process.env.REVIEW_RULES_REF || '';
const RULE_FILES = ['CLAUDE.md', 'AGENTS.md', 'REVIEW.md', '.ai/review-conventions.md'];
// A CLAUDE.md or AGENTS.md below the root holds the rules for the files under its
// directory: a module, a theme, a package. Found by name in the same tree the root
// files are read from, outside the directories that hold other people's code, and
// only so many of them, so a monorepo does not bury the root rules.
const NESTED_RULE_NAMES = new Set(['CLAUDE.md', 'AGENTS.md']);
const NESTED_RULE_SKIP = /^(vendor|node_modules|\.git|\.claude|\.agents|\.review-skills|\.review-instructions)\//;
const NESTED_RULE_LIMIT = 30;

// What one call returns at most. A longer text is paged: the reply says where the
// next page starts, and the tool takes an offset.
const PAGE = 6000;

function readFile(file) {
  try {
    return fs.readFileSync(file, 'utf8');
  } catch {
    return null;
  }
}

function readContext() {
  const text = readFile(CONTEXT_FILE);
  if (text === null) return {};
  try {
    return JSON.parse(text);
  } catch {
    return {};
  }
}

// One page of a text, by characters, with a pointer to the next one.
function page(text, offset) {
  const chars = Array.from(text);
  const start = Math.max(0, Math.min(Number.isInteger(offset) ? offset : 0, chars.length));
  const end = Math.min(chars.length, start + PAGE);
  let out = chars.slice(start, end).join('');
  if (end < chars.length) {
    out += `\n\n[Characters ${start}-${end} of ${chars.length}. Call again with offset ${end} for the rest.]`;
  }
  return out;
}

function readTicket(args) {
  const ticket = readFile(TICKET_FILE);
  if (ticket !== null && ticket.trim() !== '') return page(ticket, args.offset);

  const context = readContext();
  if (!context.ticket) return 'The pull request title names no ticket.';
  return `The ticket could not be read: ${context.ticket.error || 'unknown'}.`;
}

function readComments(args) {
  const context = readContext();
  let comments = (context.comments || []).filter((c) => c.is_review !== true);
  const file = typeof args.path === 'string' ? args.path.trim() : '';
  if (file !== '') comments = comments.filter((c) => c.path === file);

  if (comments.length === 0) {
    return file === '' ? 'Nobody has commented on this pull request.' : `Nobody has commented on ${file}.`;
  }

  // Oldest first, as a thread reads.
  comments.sort((a, b) => String(a.created_on || '').localeCompare(String(b.created_on || '')));
  const lines = comments.map((c) => {
    const where = c.path ? ` on ${c.path}${c.line ? `:${c.line}` : ''}` : '';
    const notes = [c.parent_id ? 'a reply' : '', c.resolved ? 'thread resolved' : ''].filter(Boolean);
    return `- ${c.author || 'Someone'}${where}${notes.length ? ` (${notes.join(', ')})` : ''}, ${c.created_on || 'undated'}:\n  ${String(c.text || '').trim().replace(/\n/g, '\n  ')}`;
  });
  return page(lines.join('\n\n'), args.offset);
}

function ruleText(file) {
  if (RULES_REF !== '') {
    try {
      return execFileSync('git', ['show', `${RULES_REF}:${file}`], {
        cwd: REPO,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'ignore'],
        maxBuffer: 16 * 1024 * 1024,
      });
    } catch {
      return null;
    }
  }
  return readFile(path.join(REPO, file));
}

// The nested rule files, from git: the destination branch's tree, or the files git
// tracks in the working tree. None when git cannot say.
function nestedRuleFiles() {
  const args = RULES_REF !== '' ? ['ls-tree', '-r', '--name-only', RULES_REF] : ['ls-files'];
  let list;
  try {
    list = execFileSync('git', args, {
      cwd: REPO,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
      maxBuffer: 64 * 1024 * 1024,
    });
  } catch {
    return [];
  }
  return list
    .split('\n')
    .filter((f) => f.includes('/') && NESTED_RULE_NAMES.has(path.posix.basename(f)) && !NESTED_RULE_SKIP.test(f))
    .sort()
    .slice(0, NESTED_RULE_LIMIT);
}

// The rule files as sections, split on markdown headings. AGENTS.md is often the
// same text as CLAUDE.md, and is then left out rather than offered twice.
function ruleSections() {
  const seen = new Set();
  const sections = [];
  for (const file of [...RULE_FILES, ...nestedRuleFiles()]) {
    const text = ruleText(file);
    if (text === null || text.trim() === '' || seen.has(text)) continue;
    seen.add(text);

    let current = { file, heading: '(top)', body: [] };
    for (const line of text.split('\n')) {
      if (/^#{1,6}\s/.test(line)) {
        if (current.body.join('').trim() !== '' || current.heading !== '(top)') sections.push(current);
        current = { file, heading: line.replace(/^#+\s*/, '').trim(), body: [] };
      } else {
        current.body.push(line);
      }
    }
    sections.push(current);
  }
  return sections;
}

function readRules(args) {
  const sections = ruleSections();
  if (sections.length === 0) {
    return `This repository has none of ${RULE_FILES.join(', ')}${RULES_REF ? ` on ${RULES_REF}` : ''}.`;
  }

  const search = typeof args.search === 'string' ? args.search.trim().toLowerCase() : '';
  if (search === '') {
    // An outline: these files run to tens of kilobytes, and a review wants the part
    // about what it is looking at, not all of it.
    const byFile = new Map();
    for (const s of sections) {
      if (!byFile.has(s.file)) byFile.set(s.file, []);
      if (s.heading !== '(top)') byFile.get(s.file).push(s.heading);
    }
    const out = ['The repository\'s rules, by file and heading. Call read_rules with a search word to read the sections that mention it. A CLAUDE.md or AGENTS.md in a subdirectory applies to the files under that directory.', ''];
    for (const [file, headings] of byFile) {
      out.push(file.includes('/') && NESTED_RULE_NAMES.has(path.posix.basename(file))
        ? `${file} (for ${path.posix.dirname(file)}/):`
        : `${file}:`);
      for (const h of headings) out.push(`- ${h}`);
      out.push('');
    }
    return page(out.join('\n'), args.offset);
  }

  const hits = sections.filter((s) => s.heading.toLowerCase().includes(search) || s.body.join('\n').toLowerCase().includes(search));
  if (hits.length === 0) return `No section of the rules mentions "${args.search}". Call read_rules without a search for the outline.`;
  return page(hits.map((s) => `## ${s.file}: ${s.heading}\n${s.body.join('\n').trim()}`).join('\n\n'), args.offset);
}

const TOOLS = [
  {
    name: 'read_ticket',
    description: 'Read the ticket this pull request belongs to: what the change is meant to do, its acceptance criteria and the discussion on it. The review background holds a shortened copy; this is the whole of it.',
    inputSchema: {
      type: 'object',
      properties: { offset: { type: 'integer', description: 'Where to continue from, when a previous call said there is more.' } },
    },
    run: readTicket,
  },
  {
    name: 'read_comments',
    description: 'Read what people said on this pull request, in full and oldest first: review threads, replies and explanations of why something is deliberate. Pass a file path to read only the comments on that file.',
    inputSchema: {
      type: 'object',
      properties: {
        path: { type: 'string', description: 'Only the comments on this file, e.g. app/Services/Billing.php.' },
        offset: { type: 'integer', description: 'Where to continue from, when a previous call said there is more.' },
      },
    },
    run: readComments,
  },
  {
    name: 'read_rules',
    description: 'Read this repository\'s own coding and review rules (CLAUDE.md, AGENTS.md, REVIEW.md, .ai/review-conventions.md, and a CLAUDE.md or AGENTS.md in a subdirectory, which applies to the files under it). Without a search it lists the sections; with one it returns the sections that mention it, e.g. "test", "controller" or "migration". Where these rules and a general checklist disagree, these rules win.',
    inputSchema: {
      type: 'object',
      properties: {
        search: { type: 'string', description: 'A word to find in the headings and text of the rules.' },
        offset: { type: 'integer', description: 'Where to continue from, when a previous call said there is more.' },
      },
    },
    run: readRules,
  },
];

function reply(id, result) {
  process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id, result }) + '\n');
}

function fail(id, code, message) {
  process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id, error: { code, message } }) + '\n');
}

function handle(message) {
  // A line of JSON that is not a message - null, a number - is not one to answer,
  // and destructuring it would throw and take the server down with it.
  if (message === null || typeof message !== 'object') return;
  const { id, method, params } = message;
  const isRequest = id !== undefined && id !== null;

  switch (method) {
    case 'initialize':
      return reply(id, {
        protocolVersion: (params && params.protocolVersion) || '2025-06-18',
        capabilities: { tools: {} },
        serverInfo: { name: 'review-central-context', version: '1.0.0' },
      });
    case 'ping':
      return reply(id, {});
    case 'tools/list':
      return reply(id, { tools: TOOLS.map(({ run, ...tool }) => tool) });
    case 'tools/call': {
      const tool = TOOLS.find((t) => t.name === (params && params.name));
      if (!tool) return fail(id, -32602, `Unknown tool: ${params && params.name}`);
      try {
        return reply(id, { content: [{ type: 'text', text: tool.run((params && params.arguments) || {}) }] });
      } catch (error) {
        return reply(id, { content: [{ type: 'text', text: `Could not read it: ${error.message}` }], isError: true });
      }
    }
    default:
      // Notifications (no id) need no answer; an unknown request gets the standard error.
      if (isRequest) fail(id, -32601, `Method not found: ${method}`);
  }
}

let buffer = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => {
  buffer += chunk;
  let newline;
  while ((newline = buffer.indexOf('\n')) >= 0) {
    const line = buffer.slice(0, newline).trim();
    buffer = buffer.slice(newline + 1);
    if (line === '') continue;
    let message;
    try {
      message = JSON.parse(line);
    } catch {
      fail(null, -32700, 'Parse error');
      continue;
    }
    handle(message);
  }
});
process.stdin.on('end', () => process.exit(0));
