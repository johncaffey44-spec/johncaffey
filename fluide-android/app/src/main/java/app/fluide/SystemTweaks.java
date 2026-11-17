package app.fluide;

import android.content.ContentResolver;
import android.content.Context;
import android.content.pm.PackageManager;
import android.provider.Settings;

/**
 * Réglages système accessibles à une appli normale.
 *
 * Seules les échelles d'animation (Settings.Global) sont modifiables, et uniquement après
 * « adb shell pm grant … WRITE_SECURE_SETTINGS ». La fréquence d'écran (peak/min_refresh_rate)
 * est un réglage System privé : Android le refuse à toute appli non système, même avec cette
 * permission. Elle ne se règle donc que par ADB (commandes fournies, script PC).
 */
final class SystemTweaks {
    static final String PERM = "android.permission.WRITE_SECURE_SETTINGS";
    static final String COMPILE_CMD = "adb shell cmd package bg-dexopt-job";

    static final float[] PRESETS = {0.5f, 1f, 0f};
    static final String[] PRESET_LABELS = {"0,5x\nRecommandé", "1x\nStandard", "Aucune\nDéconseillé"};

    private SystemTweaks() {}

    static String grantCommand(Context c) {
        return "adb shell pm grant " + c.getPackageName() + " " + PERM;
    }

    static boolean canWrite(Context c) {
        return c.checkSelfPermission(PERM) == PackageManager.PERMISSION_GRANTED;
    }

    static float[] readAnim(ContentResolver cr) {
        return new float[]{
                Settings.Global.getFloat(cr, Settings.Global.WINDOW_ANIMATION_SCALE, 1f),
                Settings.Global.getFloat(cr, Settings.Global.TRANSITION_ANIMATION_SCALE, 1f),
                Settings.Global.getFloat(cr, Settings.Global.ANIMATOR_DURATION_SCALE, 1f),
        };
    }

    /** Index du préréglage correspondant aux valeurs actuelles, ou -1. */
    static int presetIndex(float[] anim) {
        for (int i = 0; i < PRESETS.length; i++) {
            boolean all = true;
            for (float v : anim) all &= Math.abs(v - PRESETS[i]) < 0.01f;
            if (all) return i;
        }
        return -1;
    }

    /** Applique la même échelle aux trois réglages. Renvoie false sans la permission. */
    static boolean setAnim(Context c, float scale) {
        ContentResolver cr = c.getContentResolver();
        try {
            boolean ok = Settings.Global.putFloat(cr, Settings.Global.WINDOW_ANIMATION_SCALE, scale);
            ok &= Settings.Global.putFloat(cr, Settings.Global.TRANSITION_ANIMATION_SCALE, scale);
            ok &= Settings.Global.putFloat(cr, Settings.Global.ANIMATOR_DURATION_SCALE, scale);
            return ok;
        } catch (SecurityException e) {
            return false;
        }
    }

    static String refreshForceCommands(int hz) {
        return "adb shell settings put system peak_refresh_rate " + hz + ".0\n"
                + "adb shell settings put system min_refresh_rate " + hz + ".0";
    }

    static String refreshResetCommands() {
        return "adb shell settings delete system min_refresh_rate\n"
                + "adb shell settings delete system peak_refresh_rate";
    }
}
