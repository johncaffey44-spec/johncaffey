package app.fluide;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/** Historique des mesures (sur le téléphone uniquement), pour comparer avant / après. */
final class ResultStore {
    static final String PREFS = "fluide";
    static final String KEY = "mesures";
    static final int MAX = 30;

    static final class Entry {
        long time;
        int frames;
        double latePct, fps, hz, p95, budgetMs;
        boolean deadlineBased;
        // Conditions de la mesure : sans elles, une comparaison ne veut rien dire.
        float anim = 1f;
        boolean powerSave;
        int thermal = -1;
        float temp = Float.NaN;

        JSONObject toJson() throws JSONException {
            JSONObject o = new JSONObject();
            o.put("t", time);
            o.put("n", frames);
            o.put("late", latePct);
            o.put("fps", fps);
            o.put("hz", hz);
            o.put("p95", p95);
            o.put("budget", budgetMs);
            o.put("dl", deadlineBased);
            o.put("anim", (double) anim);
            o.put("ps", powerSave);
            o.put("th", thermal);
            if (!Float.isNaN(temp)) o.put("temp", (double) temp);
            return o;
        }

        static Entry fromJson(JSONObject o) {
            Entry e = new Entry();
            e.time = o.optLong("t");
            e.frames = o.optInt("n");
            e.latePct = o.optDouble("late", 0);
            e.fps = o.optDouble("fps", 0);
            e.hz = o.optDouble("hz", 0);
            e.p95 = o.optDouble("p95", 0);
            e.budgetMs = o.optDouble("budget", 0);
            e.deadlineBased = o.optBoolean("dl");
            e.anim = (float) o.optDouble("anim", 1);
            e.powerSave = o.optBoolean("ps");
            e.thermal = o.optInt("th", -1);
            e.temp = o.has("temp") ? (float) o.optDouble("temp") : Float.NaN;
            return e;
        }
    }

    private ResultStore() {}

    private static SharedPreferences prefs(Context c) {
        return c.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    /** La plus récente en premier. */
    static List<Entry> load(Context c) {
        List<Entry> out = new ArrayList<>();
        try {
            JSONArray a = new JSONArray(prefs(c).getString(KEY, "[]"));
            for (int i = 0; i < a.length(); i++) out.add(Entry.fromJson(a.getJSONObject(i)));
        } catch (JSONException ignored) {
            // historique illisible : on repart de zéro
        }
        return out;
    }

    static void add(Context c, Entry e) {
        List<Entry> all = load(c);
        all.add(0, e);
        JSONArray a = new JSONArray();
        try {
            for (int i = 0; i < all.size() && i < MAX; i++) a.put(all.get(i).toJson());
        } catch (JSONException ignored) {
            return;
        }
        prefs(c).edit().putString(KEY, a.toString()).apply();
    }

    static void clear(Context c) {
        prefs(c).edit().remove(KEY).apply();
    }
}
