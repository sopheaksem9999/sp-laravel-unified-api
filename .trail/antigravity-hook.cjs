#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

function readStdin() {
  try {
    return JSON.parse(fs.readFileSync(0, 'utf8'));
  } catch {
    return null;
  }
}

function field(text, key) {
  const line = text.split('\n').find((l) => l.startsWith(key + ':'));
  const v = line ? line.slice(key.length + 1).trim() : '';
  if (/^'.*'$/.test(v) || /^".*"$/.test(v)) return v.slice(1, -1);
  return v;
}

function getLearnings(projectDir) {
  const dir = path.join(projectDir, '.trail', 'learnings');
  let files = [];
  try {
    files = fs.readdirSync(dir).filter((f) => f.endsWith('.md')).sort().reverse();
  } catch {
    return [];
  }
  const items = [];
  for (const f of files.slice(0, 10)) {
    try {
      const content = fs.readFileSync(path.join(dir, f), 'utf8');
      const title = field(content, 'title') || f;
      const author = field(content, 'author');
      items.push(`- ${title}${author ? ` (${author})` : ''} · .trail/learnings/${f}`);
    } catch {}
  }
  return items;
}

function main() {
  const input = readStdin() || {};
  // Only inject on first turn of the conversation to avoid token overhead
  if (input.invocationNum && input.invocationNum > 1) {
    process.stdout.write(JSON.stringify({}));
    return;
  }

  const projectDir = (input.workspacePaths && input.workspacePaths[0]) || process.cwd();
  const learnings = getLearnings(projectDir);

  const lines = [
    '[trail] Team memory & code graph active for this repository.',
    'Tools available: `trail ask "<task>" --source` (locate + understand), `trail grep "<literal>"`, `trail skeleton <file>`, `trail callers <symbol>`, `trail check`.',
  ];

  if (learnings.length > 0) {
    lines.push('Learnings from past sessions (read before exploring):');
    lines.push(...learnings);
  }

  lines.push('Save new learnings with `trail note --title "..."` and run `trail check` before committing.');

  const output = {
    injectSteps: [
      {
        ephemeralMessage: lines.join('\n')
      }
    ]
  };

  process.stdout.write(JSON.stringify(output));
}

try {
  main();
} catch {
  process.stdout.write(JSON.stringify({}));
}
