/*
 * Add this method (and make Participant / Outcome / Factory visible)
 * to the existing FullAuthorWorker class inside the research APK.
 *
 * It reuses the exact same execute / inspect / evaluate path that
 * already works for the file-based E090 flow.
 */

// Inside FullAuthorWorker:

public static Outcome executeDynamicBatch(Context ctx, List<Participant> participants) throws Exception {
    if (ctx == null) throw new IllegalStateException("no_context");
    processContext = ctx.getApplicationContext() != null ? ctx.getApplicationContext() : ctx;

    // Foreground check (same as requireForeground)
    JSONObject state = NativeForegroundGuard.observe(processContext);
    if (!state.optBoolean("dispatch_ready", false)) {
        throw new Stop("foreground_readiness_false_or_unknown_stop");
    }

    Factory factory = new Factory();
    Group g = new Group("ipc_dynamic_batch", "ipc_" + System.currentTimeMillis(), null, null);
    for (Participant p : participants) g.add(p);

    Outcome outcome = execute(factory, g);
    evaluate(g, outcome);
    // writeOutcome is optional for IPC; we only need the maps filled
    writeOutcome(g, outcome);
    return outcome;
}

// Also change these from package-private / private to public static
// so RegionSourceProvider (same package) can use them:
//   public static final class Participant
//   public static final class Outcome
//   public static final class Group
//   static Outcome execute(...)
//   static void evaluate(...)
//   static void writeOutcome(...)
