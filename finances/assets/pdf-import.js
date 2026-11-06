// Boussole — lecture d'un relevé PDF DANS LE NAVIGATEUR (pdf.js, fourni dans vendor/pdfjs).
// Le PDF n'est envoyé à aucun service extérieur : on en extrait le texte ligne par ligne, avec la
// position horizontale de chaque morceau, et c'est api.php qui reconnaît les colonnes Débit / Crédit.

export async function extractPdfLines(file, askPassword) {
  const pdfjs = await import('../vendor/pdfjs/pdf.min.js');
  pdfjs.GlobalWorkerOptions.workerSrc = new URL('../vendor/pdfjs/pdf.worker.min.js', import.meta.url).href;
  const data = new Uint8Array(await file.arrayBuffer());
  let doc = null;
  let password;
  for (let attempt = 0; attempt < 4 && !doc; attempt++) {
    try {
      // isEvalSupported:false : aucune génération de code à partir du PDF (sécurité)
      doc = await pdfjs.getDocument({ data: data.slice(), password, isEvalSupported: false, disableFontFace: true, useSystemFonts: false, verbosity: 0 }).promise;
    } catch (e) {
      if (e && e.name === 'PasswordException') {
        password = await askPassword(attempt > 0);
        if (password == null) throw new Error('PDF protégé par un mot de passe : saisie annulée.');
        continue;
      }
      throw new Error('PDF illisible : ' + (e && e.message ? e.message : e));
    }
  }
  if (!doc) throw new Error('Mot de passe du PDF incorrect.');

  const lines = [];
  let chars = 0;
  for (let p = 1; p <= doc.numPages; p++) {
    const page = await doc.getPage(p);
    const tc = await page.getTextContent();
    const items = [];
    for (const it of tc.items) {
      if (!it.str || !it.str.trim()) continue;
      const t = it.transform;
      const h = Math.hypot(t[2], t[3]) || it.height || 8;
      items.push({ x: t[4], y: t[5], w: it.width || it.str.length * h * 0.5, h, s: it.str });
      chars += it.str.length;
    }
    // regroupe les morceaux qui partagent la même ligne de base (de haut en bas, puis de gauche à droite)
    items.sort((a, b) => (b.y - a.y) || (a.x - b.x));
    const rows = [];
    for (const it of items) {
      const last = rows[rows.length - 1];
      if (last && Math.abs(last.y - it.y) <= Math.max(1.2, Math.min(last.h, it.h) * 0.4)) last.items.push(it);
      else rows.push({ y: it.y, h: it.h, items: [it] });
    }
    for (const r of rows) {
      r.items.sort((a, b) => a.x - b.x);
      // recolle les lettres d'un même mot éclatées par certains générateurs de PDF
      const merged = [];
      for (const it of r.items) {
        const prev = merged[merged.length - 1];
        if (prev && it.x - (prev.x + prev.w) < prev.h * 0.12 && it.x >= prev.x) {
          prev.s += it.s;
          prev.w = Math.max(prev.w, it.x + it.w - prev.x);
        } else merged.push({ ...it });
      }
      lines.push({ p, i: merged.map(m => [Math.round(m.x * 10) / 10, Math.round(m.w * 10) / 10, m.s]) });
    }
    page.cleanup();
  }
  await doc.destroy();
  if (chars < 30) throw new Error('Ce PDF ne contient pas de texte : c’est sans doute un scan (image). Téléchargez le relevé « numérique » ou l’export CSV depuis votre espace bancaire.');
  return lines;
}
