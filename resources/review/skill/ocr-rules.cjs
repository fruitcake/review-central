#!/usr/bin/env node
// The repository's own rules as OCR review rules, for REVIEW_PROVIDER=ocr.
//
//   git diff --name-only ... | node ocr-rules.cjs <ref> <out.json>
//
// OCR takes no SKILL.md and no CLAUDE.md, and its background is capped at 8000
// characters, but it does take review rules: per file, matched by glob, added to
// its own language rules (merge_system_rule) and sent with that file's review. So
// the rules a repository scopes to paths - .ai/rules/*.md, Laravel Boost's included,
// each with a `paths:` list in its frontmatter - go there, per changed file, with
// REVIEW.md and .ai/review-conventions.md for every file. That leaves the background for what only
// this pull request says, and gives each file only the rules that apply to it.
//
// Read from <ref>, the destination branch, not the working tree, for the reason
// review-context-mcp.cjs gives: a pull request must not be able to rewrite the rules
// it is reviewed against. An empty <ref> reads the working tree.
//
// The changed files come on stdin, one per line. Writes nothing and exits 0 when no
// rule applies to any of them; prints one line saying what it wrote otherwise.
//
// OCR's first matching entry wins outright, and a --rule file comes before the
// repository's own .opencodereview/rule.json - so a repository that has one keeps
// it, and this writes nothing (exit 3).

'use strict';

const { execFileSync } = require('child_process');
const fs = require('fs');
const { join } = require('path');

const REF = process.argv[2] || '';
const OUT = process.argv[3] || 'ocr-rules.json';
// Per file. Rules are sent with every review group the file is in, so a repository
// with a great many of them would otherwise pay for all of them on every file; past
// this, the least specific are left out and the review is told where to read them.
const LIMIT = parseInt(process.env.OCR_RULES_LIMIT || '24000', 10);

function read(file) {
  try {
    if (REF !== '') {
      return execFileSync('git', ['show', `${REF}:${file}`], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
    }
    return fs.readFileSync(file, 'utf8');
  } catch {
    return null;
  }
}

// The stack guides to read: { file, path } for each.
function stackGuides() {
  const dir = '.review-instructions';
  let instructions = null;
  try {
    instructions = JSON.parse(fs.readFileSync(process.env.REVIEW_CONTEXT_FILE || 'review-context.json', 'utf8')).instructions || null;
  } catch {
    instructions = null;
  }
  // The precheck wrote them: the guides it listed, and only those. A stack with
  // none listed has none, as for every other reviewer of the same context.
  if (instructions && Array.isArray(instructions.files)) {
    return instructions.files
      .filter((f) => f && f.stack && /^[a-z0-9][a-z0-9._-]*\.md$/.test(f.file || ''))
      .map((f) => ({ file: `${dir}/${f.file}`, path: join(dir, f.file) }));
  }
  return (process.env.REVIEW_STACKS || '').split(/\s+/)
    .filter((stack) => /^[a-z0-9-]+$/.test(stack))
    .map((stack) => ({ file: `references/stack-${stack}.md`, path: join(__dirname, 'references', `stack-${stack}.md`) }));
}

function list(dir) {
  try {
    if (REF !== '') {
      return execFileSync('git', ['ls-tree', '-r', '--name-only', REF, '--', dir], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] })
        .split('\n').filter(Boolean);
    }
    const out = [];
    const walk = (d) => {
      for (const entry of fs.readdirSync(d, { withFileTypes: true })) {
        const path = `${d}/${entry.name}`;
        entry.isDirectory() ? walk(path) : out.push(path);
      }
    };
    walk(dir);
    return out;
  } catch {
    return [];
  }
}

// `paths:` from YAML frontmatter, as a list of quoted or bare strings. Anything
// fancier than that is not what these files contain.
function frontmatter(text) {
  const match = text.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?/);
  if (!match) return { paths: null, body: text };
  const paths = [];
  let inPaths = false;
  for (const line of match[1].split(/\r?\n/)) {
    if (/^paths\s*:/.test(line)) {
      inPaths = true;
      const inline = line.replace(/^paths\s*:\s*/, '');
      if (inline.startsWith('[')) {
        inline.replace(/^\[|\]$/g, '').split(',').map(unquote).filter(Boolean).forEach((p) => paths.push(p));
        inPaths = false;
      }
      continue;
    }
    if (inPaths && /^\s*-\s*/.test(line)) {
      paths.push(unquote(line.replace(/^\s*-\s*/, '')));
    } else if (inPaths && /^\S/.test(line)) {
      inPaths = false;
    }
  }
  return { paths: paths.length > 0 ? paths : null, body: text.slice(match[0].length) };
}

function unquote(s) {
  return s.trim().replace(/^(['"])(.*)\1$/, '$2');
}

// {a,b} expanded first, nested too, then each alternative as a regular expression:
// ** crosses directories, * and ? do not, [...] is a class. The same syntax OCR's
// doublestar matcher reads, which is what the rule files are written for.
function expand(glob) {
  const open = glob.indexOf('{');
  if (open === -1) return [glob];
  let depth = 0;
  let close = -1;
  const commas = [];
  for (let i = open; i < glob.length; i++) {
    if (glob[i] === '{') depth++;
    else if (glob[i] === '}' && --depth === 0) { close = i; break; }
    else if (glob[i] === ',' && depth === 1) commas.push(i);
  }
  if (close === -1) return [glob];
  const parts = [];
  let start = open + 1;
  for (const comma of [...commas, close]) {
    parts.push(glob.slice(start, comma));
    start = comma + 1;
  }
  const rest = glob.slice(close + 1);
  return parts.flatMap((part) => expand(glob.slice(0, open) + part + rest));
}

function toRegExp(glob) {
  let out = '';
  for (let i = 0; i < glob.length; i++) {
    const c = glob[i];
    if (c === '*' && glob[i + 1] === '*') {
      if (glob[i + 2] === '/') { out += '(?:.*/)?'; i += 2; } else { out += '.*'; i += 1; }
    } else if (c === '*') {
      out += '[^/]*';
    } else if (c === '?') {
      out += '[^/]';
    } else if (c === '[') {
      const end = glob.indexOf(']', i + 1);
      if (end === -1) { out += '\\['; continue; }
      out += '[' + glob.slice(i + 1, end).replace(/^!/, '^').replace(/\\/g, '\\\\') + ']';
      i = end;
    } else {
      out += c.replace(/[.+^${}()|\\]/g, '\\$&');
    }
  }
  return new RegExp(`^${out}$`);
}

function matcher(globs) {
  const res = globs.flatMap(expand).map(toRegExp);
  return (file) => res.some((re) => re.test(file));
}

// A path taken literally by OCR's glob matcher. A comma only means something
// inside braces, and an escaped one inside braces does not match, so a path that
// needs escaping never goes into a brace list: see plain().
function literal(path) {
  return path.replace(/[\\*?[\]{}]/g, '\\$&');
}

function plain(path) {
  return !/[\\*?[\]{},]/.test(path);
}

function main() {
  // The destination branch's, like every other rule: one the pull request adds
  // does not count, and run-ocr.sh has put the destination's in place.
  if (read('.opencodereview/rule.json') !== null) {
    console.log('This repository has its own .opencodereview/rule.json; its rules are left to apply.');
    process.exit(3);
  }

  const files = fs.readFileSync(0, 'utf8').split('\n').map((l) => l.trim()).filter(Boolean);
  if (files.length === 0) process.exit(0);

  const review = (read('REVIEW.md') || '').trim();
  const conventions = (read('.ai/review-conventions.md') || '').trim();

  // Most specific first: a rule file scoped to a few paths says more about the file
  // than one scoped to all of them, so it is the last to be left out.
  const rules = list('.ai/rules')
    .filter((f) => f.endsWith('.md') && !/(^|\/)index\.md$/.test(f))
    .map((file) => {
      const text = read(file);
      if (text === null) return null;
      const { paths, body } = frontmatter(text);
      const globs = paths || ['**'];
      const broad = globs.some((g) => g === '**' || g === '**/*');
      return { file, matches: matcher(globs), body: body.trim(), broad };
    })
    .filter((r) => r && r.body !== '')
    .sort((a, b) => (a.broad - b.broad) || a.file.localeCompare(b.file));

  // The guide for each stack the repository is built on, scoped by its own `paths:`.
  // They come with the review rather than with the repository: the precheck wrote
  // the review server's version to .review-instructions/ and listed it in the context, each
  // with its stack. Without that list - a precheck older than it - the copy beside
  // this script, for each stack in REVIEW_STACKS. After the
  // repository's rules: those are more specific, and the first to be kept.
  const guides = stackGuides()
    .map(({ file, path }) => {
      let text;
      try {
        text = fs.readFileSync(path, 'utf8');
      } catch {
        return null;
      }
      const { paths, body } = frontmatter(text);
      // Two levels down, under its own heading, so its sections read as part of it rather than as rule files.
      const nested = body.trim().replace(/^(#{1,4}) /gm, '##$1 ');
      return { file, matches: matcher(paths || ['**']), body: nested, broad: true };
    })
    .filter((r) => r && r.body !== '');

  if (review === '' && conventions === '' && rules.length === 0 && guides.length === 0) process.exit(0);

  const intro = 'The rules for this file: this repository\'s own, then the guide for the framework it is built on. They describe how this codebase is meant to work and win over the general checklist where the two disagree, and the repository\'s own win over the guide: code that follows them is not a defect, and code that breaks one is.';

  const byText = new Map();
  for (const path of files) {
    const parts = [];
    if (review !== '') {
      parts.push('## REVIEW.md\n\nThis repository\'s instructions for review: what to report, at what severity, and what to leave alone.\n\n' + review);
    }
    if (conventions !== '') {
      parts.push('## .ai/review-conventions.md\n\nPatterns judged false positives before in this repository. Do not report them again.\n\n' + conventions);
    }
    const matching = [...rules, ...guides].filter((r) => r.matches(path));
    let length = intro.length + parts.reduce((n, p) => n + p.length, 0);
    const left = [];
    for (const rule of matching) {
      const part = `## ${rule.file}\n\n${rule.body}`;
      if (length + part.length > LIMIT) { left.push(rule.file); continue; }
      parts.push(part);
      length += part.length;
    }
    if (parts.length === 0) continue;
    if (left.length > 0) {
      parts.push(`Also scoped to this file, left out for length: ${left.join(', ')}. The read_rules tool reads the repository's rules by search word.`);
    }
    const text = [intro, ...parts].join('\n\n');
    if (!byText.has(text)) byText.set(text, []);
    byText.get(text).push(path);
  }

  if (byText.size === 0) process.exit(0);

  // One entry per distinct set of rules; a brace list of literal paths so that
  // files sharing their rules share an entry, and nothing else matches it.
  const entries = [];
  for (const [rule, paths] of byText) {
    const grouped = paths.filter(plain);
    if (grouped.length === 1) entries.push({ path: grouped[0], rule, merge_system_rule: true });
    if (grouped.length > 1) entries.push({ path: `{${grouped.join(',')}}`, rule, merge_system_rule: true });
    for (const path of paths.filter((p) => !plain(p))) {
      entries.push({ path: literal(path), rule, merge_system_rule: true });
    }
  }

  fs.writeFileSync(OUT, JSON.stringify({ rules: entries }, null, 2) + '\n');
  const covered = files.length;
  console.log(`Review rules for OCR: ${covered} file(s) in ${entries.length} group(s), from ${rules.length} rule file(s)${guides.length > 0 ? ` and ${guides.length} stack guide(s)` : ''}${conventions !== '' ? ' and the review conventions' : ''}.`);
}

main();
