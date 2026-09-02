# Frontend linting — the ratchet

`npm run lint:ci` fails if the warning count goes **up**. It is not a style gate and it will not
ask you to fix code you didn't write.

```bash
npm run lint      # report everything
npm run lint:ci   # the ratchet — exits 1 above the ceiling
```

**The ceiling lives in one place: `--max-warnings` in `package.json`'s `lint:ci` script.**
It is currently **48**.

## The rule

**The number only ever goes down.** If your change pushes it up, fix the warning — don't raise the
ceiling. When you clear warnings, lower it in the same commit so the ground you took is held.

## Why this exists rather than `CI=true`

`react-scripts build` with `CI=true` promotes every warning to an error. With 50 known warnings
that means no deploy ever succeeds, so `netlify.toml` has always run `CI=false` — which promotes
nothing, and left the build catching *nothing*. Two production bugs shipped through that gap on
2026-07-30 alone (the `data.stats` contract mismatch and the `cl.team_id` phantom column; both are
in `CHANGELOG.md`). The ratchet is the middle setting: existing debt doesn't block you, new debt
can't get in.

It runs in the Netlify build (`npm run lint:ci && CI=false npm run build`), so a regression fails
the deploy rather than being discovered later.

## Scope

Test files are excluded (`**/*.test.*`, `**/__tests__/**`, `setupTests.ts`). They carry ~191
`testing-library/*` and `import/first` errors that the production build never linted and that are
a separate cleanup. Excluding them is what makes the source-file count meaningful; folding them in
would bury it.

Note the ratchet lints slightly **more** than the build does — 50 vs the build's smaller count — because
eslint reaches files that aren't in the bundle's module graph. That's deliberate: an unreferenced
component is exactly where rot goes unnoticed.

## What's left, and what it's worth

Most are `react-hooks/exhaustive-deps`. They are the ones worth real attention — an
exhaustive-deps warning is how a component ends up reading a stale prop or a closure captured on
first render. Each needs a judgment call:

- add the missing dependency, or
- wrap the dependency in `useCallback` / `useMemo` so adding it doesn't loop, or
- genuinely intend the omission — then say so in a comment, and disable the rule on that line with
  the reason.

There is no bulk fix, and `--fix` will not touch them. Take them a few at a time and drop the
ceiling as you go.

## If the ratchet fires at a bad moment

`main` is shared, so a failing lint blocks everyone's deploy. The unblock is to fix the warning —
it will be in the eslint output with a file and line. Raising the ceiling to ship is a decision to
make deliberately and undo promptly, not a routine step.
