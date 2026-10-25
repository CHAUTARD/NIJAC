/**
 * NIJAC – Encodeur QR code minimal (ISO/IEC 18004), sans dépendance ni CDN.
 *
 * Utilisé par EA99 (Sécurité TOTP) pour afficher l'URI otpauth:// à scanner avec Google
 * Authenticator. Périmètre volontairement réduit : mode octet (UTF-8), correction d'erreur
 * niveau M, versions 1 à 10 (jusqu'à 213 octets — une URI otpauth fait ~90 caractères),
 * masque choisi par la pénalité standard. Rendu SVG (net à toute taille, imprimable).
 *
 *   nijacQrSvg(texte, pixelsParModule)  → chaîne SVG (null si texte trop long)
 *   nijacQrCode(element, texte, pixelsParModule) → insère le SVG dans l'élément, true si OK
 *
 * Algorithme écrit d'après la norme (même découpage que la bibliothèque de référence
 * « QR Code generator » de Project Nayuki, licence MIT).
 */
(function (global) {
    'use strict';

    // Niveau M : octets de correction par bloc et nombre de blocs, index = version (1..10).
    const ECC_PAR_BLOC = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    const NB_BLOCS     = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    const FORMAT_ECC_M = 0; // bits de format du niveau M

    function bit(x, i) { return ((x >>> i) & 1) !== 0; }

    // Nombre de modules de données (hors motifs fonctionnels) d'une version.
    function modulesBruts(ver) {
        let r = (16 * ver + 128) * ver + 64;
        if (ver >= 2) {
            const nbAlign = Math.floor(ver / 7) + 2;
            r -= (25 * nbAlign - 10) * nbAlign - 55;
            if (ver >= 7) r -= 36;
        }
        return r;
    }

    function nbOctetsDonnees(ver) {
        return Math.floor(modulesBruts(ver) / 8) - ECC_PAR_BLOC[ver] * NB_BLOCS[ver];
    }

    // ── Reed-Solomon sur GF(256), polynôme 0x11D ──
    function gfMul(x, y) {
        let z = 0;
        for (let i = 7; i >= 0; i--) {
            z = (z << 1) ^ ((z >>> 7) * 0x11D);
            z ^= ((y >>> i) & 1) * x;
        }
        return z;
    }

    function rsDiviseur(degre) {
        const r = new Array(degre).fill(0);
        r[degre - 1] = 1;
        let racine = 1;
        for (let i = 0; i < degre; i++) {
            for (let j = 0; j < degre; j++) {
                r[j] = gfMul(r[j], racine);
                if (j + 1 < degre) r[j] ^= r[j + 1];
            }
            racine = gfMul(racine, 0x02);
        }
        return r;
    }

    function rsReste(donnees, diviseur) {
        const r = diviseur.map(() => 0);
        for (const b of donnees) {
            const facteur = b ^ r.shift();
            r.push(0);
            diviseur.forEach((c, i) => { r[i] ^= gfMul(c, facteur); });
        }
        return r;
    }

    // Découpe en blocs, ajoute la correction, entrelace.
    function ajouterCorrection(donnees, ver) {
        const nbBlocs    = NB_BLOCS[ver];
        const eccLen     = ECC_PAR_BLOC[ver];
        const totalOct   = Math.floor(modulesBruts(ver) / 8);
        const nbCourts   = nbBlocs - totalOct % nbBlocs;
        const lenCourt   = Math.floor(totalOct / nbBlocs) - eccLen; // octets de données d'un bloc court
        const diviseur   = rsDiviseur(eccLen);
        const blocsD     = [];
        const blocsE     = [];
        let k = 0;
        for (let i = 0; i < nbBlocs; i++) {
            const len = lenCourt + (i < nbCourts ? 0 : 1);
            const d   = donnees.slice(k, k + len);
            k += len;
            blocsD.push(d);
            blocsE.push(rsReste(d, diviseur));
        }
        const res = [];
        for (let i = 0; i <= lenCourt; i++) {
            blocsD.forEach(d => { if (i < d.length) res.push(d[i]); });
        }
        for (let i = 0; i < eccLen; i++) {
            blocsE.forEach(e => res.push(e[i]));
        }
        return res;
    }

    function positionsAlignement(ver) {
        if (ver === 1) return [];
        const taille = ver * 4 + 17;
        const nb     = Math.floor(ver / 7) + 2;
        const pas    = Math.ceil((ver * 4 + 4) / (nb * 2 - 2)) * 2;
        const res    = [6];
        for (let pos = taille - 7; res.length < nb; pos -= pas) res.splice(1, 0, pos);
        return res;
    }

    function masque(m, x, y) {
        switch (m) {
            case 0: return (x + y) % 2 === 0;
            case 1: return y % 2 === 0;
            case 2: return x % 3 === 0;
            case 3: return (x + y) % 3 === 0;
            case 4: return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0;
            case 5: return (x * y) % 2 + (x * y) % 3 === 0;
            case 6: return ((x * y) % 2 + (x * y) % 3) % 2 === 0;
            default: return ((x + y) % 2 + (x * y) % 3) % 2 === 0;
        }
    }

    function construire(octets) {
        // Plus petite version qui contient les données (mode octet).
        let ver = 0;
        for (let v = 1; v <= 10; v++) {
            const bitsCompte = v <= 9 ? 8 : 16;
            if (4 + bitsCompte + octets.length * 8 <= nbOctetsDonnees(v) * 8) { ver = v; break; }
        }
        if (!ver) return null;

        // ── Flux de bits ──
        const bits = [];
        const ajout = (val, n) => { for (let i = n - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
        ajout(0x4, 4);
        ajout(octets.length, ver <= 9 ? 8 : 16);
        octets.forEach(b => ajout(b, 8));
        const capacite = nbOctetsDonnees(ver) * 8;
        ajout(0, Math.min(4, capacite - bits.length));
        ajout(0, (8 - bits.length % 8) % 8);
        for (let p = 0xEC; bits.length < capacite; p ^= 0xEC ^ 0x11) ajout(p, 8);
        const donnees = [];
        for (let i = 0; i < bits.length; i += 8) {
            let b = 0;
            for (let j = 0; j < 8; j++) b = (b << 1) | bits[i + j];
            donnees.push(b);
        }
        const codewords = ajouterCorrection(donnees, ver);

        // ── Matrice ──
        const taille = ver * 4 + 17;
        const mod    = [];
        const fonc   = [];
        for (let y = 0; y < taille; y++) { mod.push(new Array(taille).fill(false)); fonc.push(new Array(taille).fill(false)); }
        const poser = (x, y, noir) => { mod[y][x] = noir; fonc[y][x] = true; };

        for (let i = 0; i < taille; i++) { poser(6, i, i % 2 === 0); poser(i, 6, i % 2 === 0); }
        [[3, 3], [taille - 4, 3], [3, taille - 4]].forEach(([cx, cy]) => {
            for (let dy = -4; dy <= 4; dy++) {
                for (let dx = -4; dx <= 4; dx++) {
                    const d = Math.max(Math.abs(dx), Math.abs(dy)), xx = cx + dx, yy = cy + dy;
                    if (xx >= 0 && xx < taille && yy >= 0 && yy < taille) poser(xx, yy, d !== 2 && d !== 4);
                }
            }
        });
        const align = positionsAlignement(ver);
        const nA    = align.length;
        for (let i = 0; i < nA; i++) {
            for (let j = 0; j < nA; j++) {
                if ((i === 0 && j === 0) || (i === 0 && j === nA - 1) || (i === nA - 1 && j === 0)) continue;
                for (let dy = -2; dy <= 2; dy++) {
                    for (let dx = -2; dx <= 2; dx++) poser(align[i] + dx, align[j] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
                }
            }
        }

        const poserFormat = (m) => {
            const data = (FORMAT_ECC_M << 3) | m;
            let rem = data;
            for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
            const b = ((data << 10) | rem) ^ 0x5412;
            for (let i = 0; i <= 5; i++) poser(8, i, bit(b, i));
            poser(8, 7, bit(b, 6));
            poser(8, 8, bit(b, 7));
            poser(7, 8, bit(b, 8));
            for (let i = 9; i < 15; i++) poser(14 - i, 8, bit(b, i));
            for (let i = 0; i < 8; i++) poser(taille - 1 - i, 8, bit(b, i));
            for (let i = 8; i < 15; i++) poser(8, taille - 15 + i, bit(b, i));
            poser(8, taille - 8, true); // module sombre fixe
        };
        poserFormat(0); // réserve les zones de format

        if (ver >= 7) {
            let rem = ver;
            for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
            const b = (ver << 12) | rem;
            for (let i = 0; i < 18; i++) {
                const a = taille - 11 + i % 3, c = Math.floor(i / 3);
                poser(a, c, bit(b, i));
                poser(c, a, bit(b, i));
            }
        }

        // Placement en zigzag des codewords.
        let i = 0;
        for (let droite = taille - 1; droite >= 1; droite -= 2) {
            if (droite === 6) droite = 5;
            for (let v = 0; v < taille; v++) {
                for (let j = 0; j < 2; j++) {
                    const x = droite - j;
                    const y = ((droite + 1) & 2) === 0 ? taille - 1 - v : v;
                    if (!fonc[y][x] && i < codewords.length * 8) {
                        mod[y][x] = bit(codewords[i >>> 3], 7 - (i & 7));
                        i++;
                    }
                }
            }
        }

        const appliquer = (m) => {
            for (let y = 0; y < taille; y++) {
                for (let x = 0; x < taille; x++) {
                    if (!fonc[y][x] && masque(m, x, y)) mod[y][x] = !mod[y][x];
                }
            }
        };

        // Choix du masque par pénalité (N1 séries, N2 carrés 2×2, N3 motifs de repérage, N4 équilibre).
        const penalite = () => {
            let p = 0, noirs = 0;
            const ligne = (get) => {
                let run = 1;
                for (let k = 1; k < taille; k++) {
                    if (get(k) === get(k - 1)) { run++; if (run === 5) p += 3; else if (run > 5) p++; }
                    else run = 1;
                }
                for (let k = 0; k + 10 < taille + 0; k++) {
                    const s = [];
                    for (let q = 0; q < 11; q++) s.push(get(k + q) ? 1 : 0);
                    const t = s.join('');
                    if (t === '10111010000' || t === '00001011101') p += 40;
                }
            };
            for (let y = 0; y < taille; y++) ligne(k => mod[y][k]);
            for (let x = 0; x < taille; x++) ligne(k => mod[k][x]);
            for (let y = 0; y < taille - 1; y++) {
                for (let x = 0; x < taille - 1; x++) {
                    const c = mod[y][x];
                    if (c === mod[y][x + 1] && c === mod[y + 1][x] && c === mod[y + 1][x + 1]) p += 3;
                }
            }
            mod.forEach(r => r.forEach(c => { if (c) noirs++; }));
            const total = taille * taille;
            p += Math.floor(Math.abs(noirs * 20 - total * 10) / total) * 10;
            return p;
        };

        let meilleur = 0, min = Infinity;
        for (let m = 0; m < 8; m++) {
            appliquer(m);
            poserFormat(m);
            const p = penalite();
            if (p < min) { min = p; meilleur = m; }
            appliquer(m); // annule (XOR)
        }
        appliquer(meilleur);
        poserFormat(meilleur);
        return mod;
    }

    function octetsUtf8(texte) {
        if (typeof TextEncoder !== 'undefined') return Array.from(new TextEncoder().encode(texte));
        return Array.from(unescape(encodeURIComponent(texte)), c => c.charCodeAt(0));
    }

    function nijacQrSvg(texte, pixelsParModule) {
        const mod = construire(octetsUtf8(String(texte)));
        if (!mod) return null;
        const marge  = 4; // zone de silence réglementaire
        const n      = mod.length + marge * 2;
        const px     = pixelsParModule || 5;
        let chemin   = '';
        mod.forEach((ligne, y) => ligne.forEach((noir, x) => {
            if (noir) chemin += `M${x + marge},${y + marge}h1v1h-1z`;
        }));
        return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${n} ${n}" width="${n * px}" height="${n * px}"`
            + ` shape-rendering="crispEdges" role="img" aria-label="QR code">`
            + `<rect width="${n}" height="${n}" fill="#fff"/><path d="${chemin}" fill="#000"/></svg>`;
    }

    function nijacQrCode(element, texte, pixelsParModule) {
        const svg = nijacQrSvg(texte, pixelsParModule);
        if (!svg || !element) return false;
        element.innerHTML = svg;
        return true;
    }

    global.nijacQrSvg  = nijacQrSvg;
    global.nijacQrCode = nijacQrCode;
    global.nijacQrMatrice = (texte) => construire(octetsUtf8(String(texte))); // tests
})(window);
