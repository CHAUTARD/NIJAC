const pptxgen = require("pptxgenjs");

const pres = new pptxgen();
pres.layout = "LAYOUT_16x9";
pres.author = "Patrick CHAUTARD";
pres.title = "NIJAC - Nouveaux écrans Nominateurs";

// Palette
const C = {
  dark:    "1A3A5C",  // bleu nuit
  mid:     "1C7293",  // teal
  light:   "E8F4FD",  // bleu très clair
  white:   "FFFFFF",
  accent:  "02C39A",  // mint
  text:    "1E293B",
  muted:   "64748B",
  blue1:   "4472C4",  // JA1
  green2:  "2E7D32",  // JA2
  orange3: "E65100",  // JA3
  red:     "C62828",
};

const makeShadow = () => ({ type: "outer", blur: 8, offset: 3, angle: 135, color: "000000", opacity: 0.12 });

// ─── SLIDE 1 : TITRE ───────────────────────────────────────────────────────────
{
  const s = pres.addSlide();
  s.background = { color: C.dark };

  // Bande accent en bas
  s.addShape(pres.shapes.RECTANGLE, { x: 0, y: 5.0, w: 10, h: 0.625, fill: { color: C.mid }, line: { color: C.mid } });

  // Titre
  s.addText("NIJAC", {
    x: 0.6, y: 0.9, w: 8.8, h: 1.0,
    fontSize: 54, bold: true, color: C.white, fontFace: "Calibri",
    align: "center", margin: 0
  });
  s.addText("Nomination Informatisée des Juges-Arbitres Championnat", {
    x: 0.6, y: 1.85, w: 8.8, h: 0.55,
    fontSize: 16, color: C.accent, fontFace: "Calibri", align: "center", margin: 0
  });

  // Sous-titre
  s.addText("Nouveaux écrans — Partie Nominateur", {
    x: 0.6, y: 2.65, w: 8.8, h: 0.7,
    fontSize: 26, bold: true, color: C.white, fontFace: "Calibri", align: "center", margin: 0
  });

  // Ligne séparatrice
  s.addShape(pres.shapes.LINE, { x: 2.5, y: 3.5, w: 5, h: 0, line: { color: C.accent, width: 2 } });

  // Métadonnées
  s.addText("Ligue Normandie de Tennis de Table  •  Version 0.0.10  •  Juin 2026", {
    x: 0.6, y: 3.8, w: 8.8, h: 0.4,
    fontSize: 12, color: "AECBDE", fontFace: "Calibri", align: "center", margin: 0
  });

  s.addText("Auteur : Patrick CHAUTARD", {
    x: 0.6, y: 4.2, w: 8.8, h: 0.35,
    fontSize: 11, color: "AECBDE", fontFace: "Calibri", align: "center", margin: 0
  });

  // Bas de page
  s.addText("Ligue Normandie de Tennis de Table", {
    x: 0.3, y: 5.1, w: 9.4, h: 0.4,
    fontSize: 11, color: C.white, fontFace: "Calibri", align: "center", margin: 0
  });
}

// ─── SLIDE 2 : VUE D'ENSEMBLE ──────────────────────────────────────────────────
{
  const s = pres.addSlide();
  s.background = { color: C.light };

  s.addShape(pres.shapes.RECTANGLE, { x: 0, y: 0, w: 10, h: 0.85, fill: { color: C.dark }, line: { color: C.dark } });
  s.addText("Partie Nominateur — Vue d'ensemble", {
    x: 0.4, y: 0.1, w: 9.2, h: 0.65,
    fontSize: 22, bold: true, color: C.white, fontFace: "Calibri", margin: 0
  });

  const ecrans = [
    { code: "E001",  titre: "Connexion (index.php)",      couleur: "5C4033",  desc: "Login / mot de passe — redirection selon le rôle (Admin ou Nominateur)" },
    { code: "E020",  titre: "Menu Nominateur",             couleur: C.mid,     desc: "Accès à toutes les fonctions de nomination" },
    { code: "E021",  titre: "Disponibilités JA",           couleur: C.blue1,   desc: "Consultation & saisie des dispos par département" },
    { code: "E022",  titre: "Nomination des JA",           couleur: "1E8449",  desc: "Nomination auto ou manuelle aux rencontres" },
    { code: "E023",  titre: "Convocation & Frais",         couleur: C.orange3, desc: "Convocation officielle + déclaration des frais" },
    { code: "E024",  titre: "Centre d'envoi",              couleur: C.red,     desc: "Envoi groupé de messages aux JA (4 types)" },
  ];

  ecrans.forEach((e, i) => {
    const x = 0.4 + (i % 3) * 3.1;
    const y = i < 3 ? 1.05 : 2.9;

    s.addShape(pres.shapes.RECTANGLE, { x, y, w: 2.9, h: 1.65,
      fill: { color: C.white }, line: { color: e.couleur, width: 2 }, shadow: makeShadow() });
    s.addShape(pres.shapes.RECTANGLE, { x, y, w: 2.9, h: 0.38,
      fill: { color: e.couleur }, line: { color: e.couleur } });
    s.addText(e.code, {
      x: x + 0.1, y: y + 0.04, w: 2.7, h: 0.3,
      fontSize: 13, bold: true, color: C.white, fontFace: "Calibri", margin: 0
    });
    s.addText(e.titre, {
      x: x + 0.1, y: y + 0.44, w: 2.7, h: 0.45,
      fontSize: 12, bold: true, color: C.text, fontFace: "Calibri", margin: 0
    });
    s.addText(e.desc, {
      x: x + 0.1, y: y + 0.9, w: 2.7, h: 0.65,
      fontSize: 10, color: C.muted, fontFace: "Calibri", margin: 0, wrap: true
    });
  });
}

// ─── SLIDE 3 : E020 Menu Nominateur ───────────────────────────────────────────
{
  const s = pres.addSlide();
  s.background = { color: C.white };

  s.addShape(pres.shapes.RECTANGLE, { x: 0, y: 0, w: 10, h: 0.85, fill: { color: C.mid }, line: { color: C.mid } });
  s.addText("E020 — Menu Nominateur", {
    x: 0.4, y: 0.1, w: 9.2, h: 0.65,
    fontSize: 22, bold: true, color: C.white, fontFace: "Calibri", margin: 0
  });

  // Description
  s.addText("Menu principal des nominateurs. Donne accès à toutes les fonctions de nomination et à la consultation des disponibilités.", {
    x: 0.5, y: 1.0, w: 9, h: 0.6,
    fontSize: 13, color: C.text, fontFace: "Calibri", margin: 0, wrap: true
  });

  const boutons = [
    { label: "Disponibilités JA (E021)",    couleur: C.blue1 },
    { label: "Nomination JA (E022)",        couleur: "1E8449" },
    { label: "Centre d'envoi (E024)",       couleur: C.red },
    { label: "Menu Administrateur",         couleur: C.mid },
    { label: "Se déconnecter",              couleur: C.muted },
  ];

  boutons.forEach((b, i) => {
    const col = i % 3;
    const row = Math.floor(i / 3);
    const x = 0.5 + col * 3.1;
    const y = 1.75 + row * 1.5;
    s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x, y, w: 2.8, h: 1.1,
      fill: { color: b.couleur }, line: { color: b.couleur }, rectRadius: 0.08, shadow: makeShadow() });
    s.addText(b.label, {
      x, y: y + 0.25, w: 2.8, h: 0.6,
      fontSize: 12, bold: true, color: C.white, fontFace: "Calibri", align: "center", margin: 0, wrap: true
    });
  });

  s.addText("La barre d'outils affiche le nom de l'utilisateur, son département et un avertissement si le mot de passe doit être changé.", {
    x: 0.5, y: 5.1, w: 9, h: 0.4,
    fontSize: 10, italic: true, color: C.muted, fontFace: "Calibri", margin: 0
  });
}

// ─── SLIDE 4 : E021 Disponibilités JA ─────────────────────────────────────────
{
  const s = pres.addSlide();
  s.background = { color: C.light };

  s.addShape(pres.shapes.RECTANGLE, { x: 0, y: 0, w: 10, h: 0.85, fill: { color: C.blue1 }, line: { color: C.blue1 } });
  s.addText("E021 — Disponibilités des Juges-Arbitres", {
    x: 0.4, y: 0.1, w: 9.2, h: 0.65,
    fontSize: 22, bold: true, color: C.white, fontFace: "Calibri", margin: 0
  });

  // Colonne gauche : description
  s.addText("Description", {
    x: 0.4, y: 1.0, w: 4.5, h: 0.4,
    fontSize: 14, bold: true, color: C.dark, fontFace: "Calibri", margin: 0
  });
  s.addText([
    { text: "Visualisation de l'état des déclarations de disponibilité par département.\nSeuls les JA actifs sont affichés.\nUn clic sur une carte ouvre la fiche de saisie individuelle.", options: { breakLine: false } }
  ], {
    x: 0.4, y: 1.45, w: 4.5, h: 1.1,
    fontSize: 12, color: C.text, fontFace: "Calibri", margin: 0, wrap: true
  });

  s.addText("Fonctionnalités", {
    x: 0.4, y: 2.65, w: 4.5, h: 0.4,
    fontSize: 14, bold: true, color: C.dark, fontFace: "Calibri", margin: 0
  });
  s.addText([
    { text: "Sélecteur de département (14, 27, 50, 61, 76)", options: { bullet: true, breakLine: true } },
    { text: "Inclusion automatique de l'Eure (27) avec Seine-Maritime (76)", options: { bullet: true, breakLine: true } },
    { text: "Grille de cartes JA : avatar, grade, nom, club, ville", options: { bullet: true, breakLine: true } },
    { text: "Permet de saisir les disponibilités d'un JA qui aurait oublié", options: { bullet: true } },
  ], {
    x: 0.4, y: 3.1, w: 4.5, h: 1.7,
    fontSize: 11, color: C.text, fontFace: "Calibri", margin: 0
  });

  // Colonne droite : légende avatars
  s.addText("Code couleur des avatars", {
    x: 5.2, y: 1.0, w: 4.4, h: 0.4,
    fontSize: 14, bold: true, color: C.dark, fontFace: "Calibri", margin: 0
  });

  const grades = [
    { label: "JA Grade 1", couleur: C.blue1 },
    { label: "JA Grade 2", couleur: C.green2 },
    { label: "JA Grade 3", couleur: C.orange3 },
    { label: "Sans disponibilité déclarée", couleur: C.red },
  ];
  grades.forEach((g, i) => {
    const y = 1.55 + i * 0.75;
    s.addShape(pres.shapes.OVAL, { x: 5.2, y, w: 0.52, h: 0.52, fill: { color: g.couleur }, line: { color: g.couleur } });
    s.addText("JA", { x: 5.2, y: y + 0.08, w: 0.52, h: 0.36, fontSize: 10, bold: true, color: C.white, align: "center", fontFace: "Calibri", margin: 0 });
    s.addText(g.label, { x: 5.85, y: y + 0.08, w: 3.6, h: 0.36, fontSize: 12, color: C.text, fontFace: "Calibri", margin: 0 });
  });

  s.addShape(pres.shapes.RECTANGLE, { x: 5.2, y: 4.65, w: 4.4, h: 0.75,
    fill: { color: "FFF3CD" }, line: { color: "E6AC00", width: 1 } });
  s.addText("Les JA avec avatar rouge doivent être relancés pour saisir leurs disponibilités avant la nomination.", {
    x: 5.35, y: 4.7, w: 4.1, h: 0.65,
    fontSize: 10, italic: true, color: "7A5C00", fontFace: "Calibri", margin: 0, wrap: true
  });
}

// ─── SLIDE 5 : E022 Nomination des JA ─────────────────────────────────────────
{
  const s = pres.addSlide();
  s.background = { color: C.white };

  s.addShape(pres.shapes.RECTANGLE, { x: 0, y: 0, w: 10, h: 0.85, fill: { color: "1E8449" }, line: { color: "1E8449" } });
  s.addText("E022 — Nomination des Juges-Arbitres", {
    x: 0.4, y: 0.1, w: 9.2, h: 0.65,
    fontSize: 22, bold: true, color: C.white, fontFace: "Calibri", margin: 0
  });

  s.addText("Interface principale de nomination des JA aux rencontres de la saison.", {
    x: 0.4, y: 1.0, w: 9.2, h: 0.4,
    fontSize: 13, color: C.text, fontFace: "Calibri", margin: 0
  });

  // Colonne gauche : fonctionnalités
  s.addText("Fonctionnalités", {
    x: 0.4, y: 1.5, w: 4.5, h: 0.38,
    fontSize: 14, bold: true, color: C.dark, fontFace: "Calibri", margin: 0
  });
  s.addText([
    { text: "Sélecteur de saison et de journée", options: { bullet: true, breakLine: true } },
    { text: "Tableau des rencontres avec statut de nomination", options: { bullet: true, breakLine: true } },
    { text: "Nomination automatique (algorithme métier)", options: { bullet: true, breakLine: true } },
    { text: "Modification manuelle : clic sur une rencontre", options: { bullet: true, breakLine: true } },
    { text: "Liste des 5 premiers JA nominables filtrés", options: { bullet: true, breakLine: true } },
    { text: "Envoi groupé des convocations par email", options: { bullet: true } },
  ], {
    x: 0.4, y: 1.95, w: 4.6, h: 2.2,
    fontSize: 11, color: C.text, fontFace: "Calibri", margin: 0
  });

  // Colonne droite : règles métier
  s.addShape(pres.shapes.RECTANGLE, { x: 5.2, y: 1.5, w: 4.5, h: 3.8,
    fill: { color: "F0F9F4" }, line: { color: "1E8449", width: 1 }, shadow: makeShadow() });
  s.addText("Règles de nomination", {
    x: 5.35, y: 1.65, w: 4.2, h: 0.38,
    fontSize: 13, bold: true, color: "1E8449", fontFace: "Calibri", margin: 0
  });
  s.addText([
    { text: "Un JA ne peut pas arbitrer si son club est impliqué dans la rencontre", options: { bullet: true, breakLine: true } },
    { text: "Maximum 2 rencontres du même club par phase pour un même JA", options: { bullet: true, breakLine: true } },
    { text: "Un JA ne peut arbitrer qu'une seule fois par date", options: { bullet: true, breakLine: true } },
    { text: "Priorité aux rencontres choisies par le JA dans ses disponibilités", options: { bullet: true, breakLine: true } },
    { text: "Priorité à la rencontre la plus proche du domicile du JA", options: { bullet: true, breakLine: true } },
    { text: "Priorité au JA ayant le moins d'arbitrages sur la phase en cours", options: { bullet: true } },
  ], {
    x: 5.35, y: 2.1, w: 4.2, h: 3.0,
    fontSize: 10, color: C.text, fontFace: "Calibri", margin: 0
  });
}

// ─── SLIDE 6 : E023 Convocation & Frais ───────────────────────────────────────
{
  const s = pres.addSlide();
  s.background = { color: C.light };

  s.addShape(pres.shapes.RECTANGLE, { x: 0, y: 0, w: 10, h: 0.85, fill: { color: C.orange3 }, line: { color: C.orange3 } });
  s.addText("E023 — Convocation et Frais JA", {
    x: 0.4, y: 0.1, w: 9.2, h: 0.65,
    fontSize: 22, bold: true, color: C.white, fontFace: "Calibri", margin: 0
  });

  s.addText("Page de convocation officielle d'un Juge-Arbitre. Accessible via token email ou depuis E022.", {
    x: 0.4, y: 1.0, w: 9.2, h: 0.4,
    fontSize: 13, color: C.text, fontFace: "Calibri", margin: 0
  });

  // Deux colonnes de cartes
  const infos = [
    { titre: "Informations rencontre",  items: ["Date et heure", "Équipes (domicile / extérieur)", "Salle et adresse complète"] },
    { titre: "Informations JA",         items: ["Nom, prénom, grade", "Distance calculée (km)", "Club du JA"] },
  ];
  const frais = [
    { titre: "Saisie des frais",        items: ["Km réels (aller-retour)", "Péages (si applicable)", "Indemnité forfaitaire (auto)", "Total des frais (calculé auto)"] },
  ];

  infos.forEach((bloc, i) => {
    const x = 0.4 + i * 4.8;
    s.addShape(pres.shapes.RECTANGLE, { x, y: 1.55, w: 4.5, h: 2.0,
      fill: { color: C.white }, line: { color: C.orange3, width: 1 }, shadow: makeShadow() });
    s.addText(bloc.titre, { x: x + 0.15, y: 1.65, w: 4.2, h: 0.38,
      fontSize: 12, bold: true, color: C.orange3, fontFace: "Calibri", margin: 0 });
    s.addText(bloc.items.map(t => ({ text: t, options: { bullet: true, breakLine: true } })).map((o, j, a) => {
      if (j === a.length - 1) o.options.breakLine = false;
      return o;
    }), {
      x: x + 0.15, y: 2.1, w: 4.2, h: 1.3,
      fontSize: 11, color: C.text, fontFace: "Calibri", margin: 0
    });
  });

  // Frais centré
  s.addShape(pres.shapes.RECTANGLE, { x: 2.4, y: 3.75, w: 5.2, h: 1.55,
    fill: { color: C.white }, line: { color: C.orange3, width: 1 }, shadow: makeShadow() });
  s.addText("Saisie des frais", { x: 2.55, y: 3.85, w: 4.9, h: 0.38,
    fontSize: 12, bold: true, color: C.orange3, fontFace: "Calibri", margin: 0 });
  s.addText([
    { text: "Km réels aller-retour", options: { bullet: true, breakLine: true } },
    { text: "Péages (si applicable)", options: { bullet: true, breakLine: true } },
    { text: "Indemnité forfaitaire calculée automatiquement (selon type de compétition)", options: { bullet: true, breakLine: true } },
    { text: "Total des frais calculé automatiquement — bouton Enregistrer", options: { bullet: true } },
  ], {
    x: 2.55, y: 4.3, w: 4.9, h: 0.95,
    fontSize: 10, color: C.text, fontFace: "Calibri", margin: 0
  });
}

// ─── SLIDE 7 : E024 Centre d'envoi ────────────────────────────────────────────
{
  const s = pres.addSlide();
  s.background = { color: C.white };

  s.addShape(pres.shapes.RECTANGLE, { x: 0, y: 0, w: 10, h: 0.85, fill: { color: C.red }, line: { color: C.red } });
  s.addText("E024 — Centre d'envoi", {
    x: 0.4, y: 0.1, w: 9.2, h: 0.65,
    fontSize: 22, bold: true, color: C.white, fontFace: "Calibri", margin: 0
  });

  s.addText("Interface d'envoi groupé de messages aux JA du département. Panneau de composition (55%) + liste destinataires (45%).", {
    x: 0.4, y: 1.0, w: 9.2, h: 0.5,
    fontSize: 12, color: C.text, fontFace: "Calibri", margin: 0, wrap: true
  });

  const onglets = [
    { num: "01", label: "Disponibilités",    couleur: C.blue1,   desc: "Envoie un message à tous les JA actifs du département pour recueillir leurs disponibilités." },
    { num: "02", label: "Rappel dispo",      couleur: "1E8449",  desc: "Envoie un rappel uniquement aux JA actifs n'ayant saisi aucune disponibilité pour la saison en cours." },
    { num: "03", label: "Convocation",       couleur: C.orange3, desc: "Envoie les convocations aux JA nommés pour une journée sélectionnable avec infos du match." },
    { num: "04", label: "Liste nomination",  couleur: C.red,     desc: "Envoie à chaque JA la liste complète de ses nominations pour la phase en cours (tableau HTML)." },
  ];

  onglets.forEach((o, i) => {
    const x = 0.35 + i * 2.35;
    const y = 1.65;
    s.addShape(pres.shapes.RECTANGLE, { x, y, w: 2.2, h: 3.5,
      fill: { color: C.light }, line: { color: o.couleur, width: 2 }, shadow: makeShadow() });
    s.addShape(pres.shapes.RECTANGLE, { x, y, w: 2.2, h: 0.55,
      fill: { color: o.couleur }, line: { color: o.couleur } });
    s.addText(o.num, { x: x + 0.08, y: y + 0.08, w: 0.4, h: 0.38,
      fontSize: 18, bold: true, color: C.white, fontFace: "Calibri", margin: 0 });
    s.addText(o.label, { x: x + 0.5, y: y + 0.1, w: 1.6, h: 0.38,
      fontSize: 12, bold: true, color: C.white, fontFace: "Calibri", margin: 0 });
    s.addText(o.desc, { x: x + 0.12, y: y + 0.7, w: 1.96, h: 2.3,
      fontSize: 10, color: C.text, fontFace: "Calibri", margin: 0, wrap: true });
  });
}

// ─── SLIDE 8 : CONCLUSION ─────────────────────────────────────────────────────
{
  const s = pres.addSlide();
  s.background = { color: C.dark };

  s.addShape(pres.shapes.RECTANGLE, { x: 0, y: 4.8, w: 10, h: 0.825, fill: { color: C.mid }, line: { color: C.mid } });

  s.addText("Récapitulatif", {
    x: 0.5, y: 0.5, w: 9, h: 0.65,
    fontSize: 30, bold: true, color: C.white, fontFace: "Calibri", align: "center", margin: 0
  });

  const recap = [
    { code: "E020", desc: "Menu Nominateur" },
    { code: "E021", desc: "Disponibilités JA — suivi par département avec code couleur grade" },
    { code: "E022", desc: "Nomination — algorithme automatique + 6 règles métier" },
    { code: "E023", desc: "Convocation officielle + saisie & calcul des frais" },
    { code: "E024", desc: "Centre d'envoi — 4 types de messages groupés" },
  ];

  recap.forEach((r, i) => {
    const y = 1.35 + i * 0.65;
    s.addShape(pres.shapes.RECTANGLE, { x: 1.5, y, w: 1.0, h: 0.42,
      fill: { color: C.accent }, line: { color: C.accent } });
    s.addText(r.code, { x: 1.5, y: y + 0.05, w: 1.0, h: 0.35,
      fontSize: 12, bold: true, color: C.dark, fontFace: "Calibri", align: "center", margin: 0 });
    s.addText(r.desc, { x: 2.65, y: y + 0.05, w: 6.5, h: 0.35,
      fontSize: 12, color: C.white, fontFace: "Calibri", margin: 0 });
  });

  s.addText("Ligue Normandie de Tennis de Table  •  Patrick CHAUTARD  •  Juin 2026", {
    x: 0.3, y: 4.9, w: 9.4, h: 0.38,
    fontSize: 11, color: C.white, fontFace: "Calibri", align: "center", margin: 0
  });
}

pres.writeFile({ fileName: "F:\\wamp64\\www\\NIJAC\\NIJAC_Presentaton_Nominateurs.pptx" })
  .then(() => console.log("✅ Fichier généré : NIJAC_Presentaton_Nominateurs.pptx"))
  .catch(e => console.error("❌ Erreur :", e));
