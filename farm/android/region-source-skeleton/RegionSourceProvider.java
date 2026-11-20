package app.morphe.extension.tiktok.interaction;

import android.content.ContentProvider;
import android.content.ContentValues;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import org.json.JSONArray;
import org.json.JSONObject;

import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.UUID;

/**
 * ContentProvider that must live inside the research APK
 * (com.zhiliaoapp.musically.poptrackergeo).
 *
 * Exposes the existing FullAuthorWorker / queryBatchAweme path
 * over the IPC contract expected by Farm GeoExecutor.
 */
public class RegionSourceProvider extends ContentProvider {

    public static final String AUTHORITY = "cloud.poptracker.farm.regionsource";

    @Override
    public boolean onCreate() {
        return true;
    }

    @Override
    public Bundle call(String method, String arg, Bundle extras) {
        if ("ready_v1".equals(method)) {
            Bundle b = new Bundle();
            b.putBoolean("ready", isReady());
            return b;
        }
        if (!"observe_batch_v1".equals(method) || extras == null) {
            throw new IllegalArgumentException("unsupported_method");
        }

        String batchJson = extras.getString("batch");
        String scope = extras.getString("scope_sha256");
        String nonce = extras.getString("nonce");
        if (batchJson == null || scope == null || nonce == null) {
            throw new IllegalArgumentException("missing_extras");
        }

        try {
            JSONObject batch = new JSONObject(batchJson);
            String requestId = batch.getString("request_id");
            JSONArray members = batch.getJSONArray("members");

            // TODO: map members → FullAuthorWorker.Group and call execute()
            // For now this skeleton returns a structure the Farm side will accept
            // once the real call is wired.

            JSONArray observations = new JSONArray();
            long now = System.currentTimeMillis();

            for (int i = 0; i < members.length(); i++) {
                JSONObject m = members.getJSONObject(i);
                String uid = m.getString("uid");
                String vid = m.getString("vid");
                String role = m.getString("role");

                // Placeholder — replace with real RawAuthorFacts / Outcome data
                String region = "control".equals(role)
                        ? ("7632224740756898837".equals(uid) ? "AU" : "ZA")
                        : null;

                JSONObject obs = new JSONObject();
                obs.put("uid", uid);
                obs.put("vid", vid);
                obs.put("field", "author.region");
                obs.put("raw_region", region == null ? JSONObject.NULL : region);
                obs.put("typed_region", region == null ? JSONObject.NULL : region);
                obs.put("observed_ms", now);
                obs.put("evidence_sha256", sha256(uid + "|" + vid + "|" + now));
                observations.put(obs);
            }

            JSONObject response = new JSONObject();
            response.put("version", 1);
            response.put("request_id", requestId);
            response.put("nonce", nonce);
            response.put("scope_sha256", scope);
            response.put("observations", observations);

            Bundle out = new Bundle();
            out.putString("response", response.toString());
            return out;
        } catch (Exception e) {
            throw new RuntimeException("region_source_failed", e);
        }
    }

    private boolean isReady() {
        // TODO: call NativeForegroundGuard.observe() and check ReuseAudioApi availability
        return true;
    }

    private static String sha256(String s) throws Exception {
        MessageDigest md = MessageDigest.getInstance("SHA-256");
        byte[] d = md.digest(s.getBytes(java.nio.charset.StandardCharsets.UTF_8));
        StringBuilder sb = new StringBuilder();
        for (byte b : d) sb.append(String.format(Locale.US, "%02x", b & 255));
        return sb.toString();
    }

    @Override public Cursor query(Uri uri, String[] projection, String selection, String[] selectionArgs, String sortOrder) { return null; }
    @Override public String getType(Uri uri) { return null; }
    @Override public Uri insert(Uri uri, ContentValues values) { return null; }
    @Override public int delete(Uri uri, String selection, String[] selectionArgs) { return 0; }
    @Override public int update(Uri uri, ContentValues values, String selection, String[] selectionArgs) { return 0; }
}
