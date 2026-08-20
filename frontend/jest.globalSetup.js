/**
 * Pin the timezone for the whole test run.
 *
 * Several date bugs in this app only reproduce in a timezone BEHIND UTC: a
 * "YYYY-MM-DD" string parsed with `new Date()` is UTC midnight, which is the
 * previous evening locally. That is how PracticeScheduler put a coach's Tuesday
 * practices on Wednesday for six Central Kansas United teams. Running the suite
 * in UTC makes that entire class of regression invisible.
 *
 * This has to be a globalSetup, not setupTests: Node caches the zone on first
 * use, and setupTests runs after that has already happened. globalSetup runs in
 * the parent process before the workers fork, so they inherit it.
 */
module.exports = () => {
  process.env.TZ = 'America/Chicago';
};
