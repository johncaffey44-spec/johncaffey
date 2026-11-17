package app.fluide;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

import java.util.ArrayList;
import java.util.List;

/** Logique pure : calculs, seuils, mise en forme. Aucun téléphone nécessaire. */
public class LogicTest {

    // ---------------------------------------------------------------- FrameStats

    @Test
    public void framesWithinBudgetAreNotLate() {
        FrameStats fs = new FrameStats();
        long period = 8_333_333L; // 120 Hz
        for (int i = 0; i < 960; i++) fs.add(5_000_000L, 0, i * period);
        FrameStats.Summary s = fs.summarize(120);
        assertEquals(960, s.frames);
        assertEquals(0, s.late);
        assertEquals(0.0, s.latePct, 1e-9);
        assertEquals(120.0, s.fps, 0.5);
        assertEquals(5.0, s.p95, 1e-9);
        assertEquals(8.333, s.budgetMs, 0.01);
        assertFalse(s.deadlineBased);
    }

    @Test
    public void lateFramesUseDeadlineWhenAvailable() {
        FrameStats fs = new FrameStats();
        long period = 16_666_667L; // 60 Hz
        // 90 images à 10 ms, 10 images à 20 ms : échéance 16,7 ms -> 10 % en retard.
        for (int i = 0; i < 100; i++) fs.add(i % 10 == 0 ? 20_000_000L : 10_000_000L, period, i * period);
        FrameStats.Summary s = fs.summarize(60);
        assertTrue(s.deadlineBased);
        assertEquals(10, s.late);
        assertEquals(10.0, s.latePct, 1e-9);
        assertEquals(10.0, s.p50, 1e-9);
        assertEquals(20.0, s.p95, 1e-9);
    }

    @Test
    public void droppedFramesLowerEffectiveFps() {
        FrameStats fs = new FrameStats();
        long period = 16_666_667L;
        // Une image sur deux manquée : vsync espacés de 2 périodes -> ~30 images/s sur un écran 60 Hz.
        for (int i = 0; i < 120; i++) fs.add(25_000_000L, period, i * 2 * period);
        FrameStats.Summary s = fs.summarize(60);
        assertEquals(30.0, s.fps, 0.5);
        assertEquals(100.0, s.latePct, 1e-9);
    }

    @Test
    public void lostReportsStillCountAsRenderedFrames() {
        FrameStats fs = new FrameStats();
        long period = 16_666_667L;
        for (int i = 0; i < 60; i++) fs.add(5_000_000L, 0, i * 2 * period); // 1 rapport sur 2 reçu
        fs.addLost(59);
        assertEquals(60.0, fs.summarize(60).fps, 0.6);
    }

    @Test
    public void emptyStatsDoNotCrash() {
        FrameStats.Summary s = new FrameStats().summarize(60);
        assertEquals(0, s.frames);
        assertEquals(0.0, s.fps, 0);
    }

    @Test
    public void percentileNearestRank() {
        long[] v = {1, 2, 3, 4, 5, 6, 7, 8, 9, 10};
        assertEquals(5, FrameStats.percentile(v, 50));
        assertEquals(10, FrameStats.percentile(v, 95));
        assertEquals(1, FrameStats.percentile(v, 1));
    }

    @Test
    public void verdictThresholds() {
        assertEquals(Ui.GOOD, FrameStats.level(0.5));
        assertEquals(Ui.GOOD, FrameStats.level(3));
        assertEquals(Ui.INFO, FrameStats.level(5));
        assertEquals(Ui.FIX, FrameStats.level(12));
        assertEquals(Ui.URGENT, FrameStats.level(30));
        assertTrue(FrameStats.verdict(0.2).startsWith("Excellent"));
        assertTrue(FrameStats.verdict(20).startsWith("Très saccadé"));
    }

    // ---------------------------------------------------------------- Fmt

    @Test
    public void frenchFormatting() {
        assertEquals("999 o", Fmt.bytes(999));
        assertEquals("1,5 Go", Fmt.bytes(1_500_000_000L));
        assertEquals("128 Go", Fmt.bytes(128_000_000_000L));
        assertEquals("23 Go", Fmt.bytes(23_000_000_000L));
        assertEquals("12,3 %", Fmt.pct(12.34));
        assertEquals("8 %", Fmt.pct(8.0));
        assertEquals("0,5x", Fmt.scale(0.5f));
        assertEquals("1x", Fmt.scale(1f));
        assertEquals("désactivées", Fmt.scale(0f));
        assertEquals("120 Hz", Fmt.hz(119.99998));
        assertEquals("il y a 45 j", Fmt.daysAgo(45));
        assertEquals("il y a 3 mois", Fmt.daysAgo(95));
        assertEquals("9 jours", Fmt.duration(9 * Fmt.DAY + 5000));
    }

    // ---------------------------------------------------------------- Diagnostics

    @Test
    public void nominalRamRoundsUpToCommercialSize() {
        long gib = 1024L * 1024 * 1024;
        assertEquals(8, Diagnostics.nominalGb((long) (7.4 * gib)));
        assertEquals(6, Diagnostics.nominalGb((long) (5.5 * gib)));
        assertEquals(4, Diagnostics.nominalGb((long) (3.6 * gib)));
        assertEquals(3, Diagnostics.nominalGb((long) (2.8 * gib)));
        assertEquals(12, Diagnostics.nominalGb((long) (11.2 * gib)));
        assertEquals(0, Diagnostics.nominalGb(0));
    }

    private static Diagnostics.Snapshot healthy() {
        Diagnostics.Snapshot s = new Diagnostics.Snapshot();
        s.sdk = 35;
        s.release = "15";
        s.ramNominalGb = 8;
        s.storageTotal = 128_000_000_000L;
        s.storageFree = 60_000_000_000L;
        s.refreshMax = s.refreshMaxAnyRes = 120f;
        s.peakSetting = 120f;
        s.animWindow = s.animTransition = s.animAnimator = 0.5f;
        s.thermal = 0;
        s.batteryTemp = 30f;
        s.uptimeMs = Fmt.DAY;
        return s;
    }

    private static List<Integer> levels(List<Diagnostics.Finding> f) {
        List<Integer> out = new ArrayList<>();
        for (Diagnostics.Finding x : f) out.add(x.level);
        return out;
    }

    private static Diagnostics.Finding find(List<Diagnostics.Finding> list, String titlePart) {
        for (Diagnostics.Finding f : list) if (f.title.contains(titlePart)) return f;
        throw new AssertionError("Constat introuvable : " + titlePart);
    }

    @Test
    public void healthyPhoneHasNothingToFix() {
        List<Diagnostics.Finding> f = Diagnostics.findings(healthy());
        assertFalse(levels(f).contains(Ui.URGENT));
        assertFalse(levels(f).contains(Ui.FIX));
        assertFalse(levels(f).contains(Ui.LIMIT));
    }

    @Test
    public void fullStorageIsUrgentAndListedFirst() {
        Diagnostics.Snapshot s = healthy();
        s.storageFree = 6_000_000_000L; // 4,7 %
        s.powerSave = true;
        List<Diagnostics.Finding> f = Diagnostics.findings(s);
        assertEquals(Ui.URGENT, f.get(0).level);
        assertEquals("Stockage presque plein", f.get(0).title);
        assertEquals(Diagnostics.ACT_TAB_CLEAN, f.get(0).action);
        assertEquals(Ui.FIX, find(f, "Économie d'énergie").level);
    }

    @Test
    public void storageThresholds() {
        Diagnostics.Snapshot s = healthy();
        s.storageFree = 19_000_000_000L; // 14,8 %
        assertEquals(Ui.FIX, find(Diagnostics.findings(s), "Stockage").level);
        s.storageFree = 30_000_000_000L; // 23 %
        assertEquals(Ui.GOOD, find(Diagnostics.findings(s), "stockage").level);
    }

    @Test
    public void hardwareLimitsAreReportedAsLimits() {
        Diagnostics.Snapshot s = healthy();
        s.ramNominalGb = 3;
        s.refreshMax = s.refreshMaxAnyRes = 60f;
        s.sdk = 30;
        s.release = "11";
        List<Diagnostics.Finding> f = Diagnostics.findings(s);
        assertEquals(Ui.LIMIT, find(f, "Mémoire vive").level);
        assertEquals(Ui.LIMIT, find(f, "60 Hz").level);
        assertEquals(Ui.LIMIT, find(f, "Android 11").level);
    }

    @Test
    public void screenCappedBelowItsMaximum() {
        Diagnostics.Snapshot s = healthy();
        s.peakSetting = 60f;
        Diagnostics.Finding f = find(Diagnostics.findings(s), "bridé");
        assertEquals(Ui.FIX, f.level);
        assertEquals(Diagnostics.ACT_DISPLAY, f.action);
    }

    @Test
    public void animationFindings() {
        Diagnostics.Snapshot s = healthy();
        s.animWindow = s.animTransition = s.animAnimator = 1f;
        assertEquals(Ui.FIX, find(Diagnostics.findings(s), "vitesse standard").level);
        s.animAnimator = 2f;
        assertEquals(Ui.FIX, find(Diagnostics.findings(s), "ralenties").level);
        s.animWindow = s.animTransition = s.animAnimator = 0f;
        assertEquals(Ui.INFO, find(Diagnostics.findings(s), "désactivées").level);
    }

    @Test
    public void heatAndUptime() {
        Diagnostics.Snapshot s = healthy();
        s.thermal = 3;
        s.uptimeMs = 12 * Fmt.DAY;
        List<Diagnostics.Finding> f = Diagnostics.findings(s);
        assertEquals(Ui.URGENT, f.get(0).level);
        assertTrue(f.get(0).title.contains("surchauffe"));
        assertEquals(Ui.INFO, find(f, "Allumé depuis 12").level);
    }

    @Test
    public void findingsAreSortedBySeverity() {
        Diagnostics.Snapshot s = healthy();
        s.storageFree = 5_000_000_000L;
        s.ramNominalGb = 4;
        s.animWindow = s.animTransition = s.animAnimator = 1f;
        s.uptimeMs = 10 * Fmt.DAY;
        List<Integer> lv = levels(Diagnostics.findings(s));
        int[] rank = new int[5];
        rank[Ui.URGENT] = 0;
        rank[Ui.FIX] = 1;
        rank[Ui.LIMIT] = 2;
        rank[Ui.INFO] = 3;
        rank[Ui.GOOD] = 4;
        for (int i = 1; i < lv.size(); i++) assertTrue(rank[lv.get(i - 1)] <= rank[lv.get(i)]);
    }

    // ---------------------------------------------------------------- Réglages et mesures

    @Test
    public void animationPresetDetection() {
        assertEquals(0, SystemTweaks.presetIndex(new float[]{0.5f, 0.5f, 0.5f}));
        assertEquals(1, SystemTweaks.presetIndex(new float[]{1f, 1f, 1f}));
        assertEquals(2, SystemTweaks.presetIndex(new float[]{0f, 0f, 0f}));
        assertEquals(-1, SystemTweaks.presetIndex(new float[]{0.5f, 1f, 1f}));
    }

    @Test
    public void refreshCommandsUseIntegerRate() {
        String c = SystemTweaks.refreshForceCommands(120);
        assertTrue(c.contains("settings put system peak_refresh_rate 120.0"));
        assertTrue(c.contains("settings put system min_refresh_rate 120.0"));
    }

    @Test
    public void compileCommandAvoidsCancellableJobOnAndroid14() {
        // Android 14+ : bg-dexopt-job est interrompu par le système (« Job cancelled »).
        assertEquals("adb shell pm art dexopt-packages -r bg-dexopt", SystemTweaks.compileCommand(34));
        assertEquals("adb shell pm art dexopt-packages -r bg-dexopt", SystemTweaks.compileCommand(36));
        assertEquals("adb shell cmd package bg-dexopt-job", SystemTweaks.compileCommand(33));
        assertTrue(SystemTweaks.COMPILE_FALLBACK_CMD.endsWith("compile -m speed-profile -a"));
    }

    @Test
    public void comparisonFlagsChangedConditions() {
        ResultStore.Entry a = new ResultStore.Entry();
        a.hz = 120;
        a.temp = 30f;
        ResultStore.Entry b = new ResultStore.Entry();
        b.hz = 60;
        b.powerSave = true;
        b.temp = 41f;
        List<String> ch = TabMesure.changes(a, b);
        assertTrue(ch.contains("économie d'énergie"));
        assertTrue(ch.contains("fréquence d'écran"));
        assertTrue(ch.contains("température"));
        assertTrue(TabMesure.changes(a, a).isEmpty());
    }

    @Test
    public void mythsAreComplete() {
        assertTrue(TabVraiFaux.MYTHS.length >= 12);
        for (TabVraiFaux.Myth m : TabVraiFaux.MYTHS) {
            assertFalse(m.claim.isEmpty());
            assertTrue(m.why.length() > 80);
        }
    }

    @Test
    public void bloatListHasNoDuplicates() {
        java.util.Set<String> seen = new java.util.HashSet<>();
        for (Bloat.Entry e : Bloat.LIST) assertTrue(e.pkg, seen.add(e.pkg));
    }
}
