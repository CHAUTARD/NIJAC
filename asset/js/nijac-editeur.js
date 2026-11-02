// Éditeur HTML visuel des modèles d'email (EN15, EA93) — TinyMCE auto-hébergé (asset/tinymce/).
// Chargé par la vue partielle partials/editeur_html.php, seulement si TinyMCE est présent :
// sans lui, les vues gardent leur textarea habituel.
//
// Principe : le textarea reste la source de vérité pour le code des vues. Deux crochets jQuery
// rendent l'éditeur transparent :
//  - $('#txt-message').val() / .val(x) lisent / écrivent le contenu de l'éditeur (valHooks) ;
//  - .prop('readonly', x) passe l'éditeur en lecture seule / édition (propHooks).
// Le contenu n'est jamais « normalisé » par l'éditeur tant que l'utilisateur n'y touche pas :
// .val() rend alors le texte d'origine à l'identique (modèles système, texte brut…).
// Un document complet (<!DOCTYPE html><html><head>…<body style="…">) est découpé : seul le
// contenu du <body> est édité, l'en-tête et la balise <body> sont recollés tels quels.
// Les commentaires conditionnels Outlook (<!--[if mso]>…<![endif]-->) sont protégés.
(function ($, tinymce) {
    'use strict';
    if (!$ || !tinymce) return;

    var CONF    = window.NIJAC_EDITEUR || {};
    var etats   = {};   // id du textarea → { ed, pret, original, avant, apres, lecture }

    // Commentaires conditionnels Outlook : blocs « mso seulement » entiers (leur contenu est un
    // commentaire HTML), puis ouvertures / fermetures « non-mso » (<!--[if !mso]><!--> … <!--<![endif]-->).
    var PROTEGES = [
        /<!--\[if (?!!)[^\]]*\]>[\s\S]*?<!\[endif\]-->/gi,
        /<!--\[if ![^\]]*\]><!-->/gi,
        /<!--<!\[endif\]-->/gi
    ];

    function echapper(t) {
        return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function estHtml(t) {
        return /<\/?[a-z][\s\S]*?>/i.test(String(t || ''));
    }

    // Texte brut → paragraphes HTML (affichage dans l'éditeur ; le texte d'origine reste rendu
    // tel quel par .val() tant qu'il n'est pas modifié).
    function texteVersHtml(t) {
        return String(t).replace(/\r\n?/g, '\n').split(/\n{2,}/)
            .map(function (p) { return '<p>' + echapper(p).replace(/\n/g, '<br>') + '</p>'; })
            .join('');
    }

    // Découpe un document complet en [avant (jusqu'à <body…> inclus), corps, après (</body>…)].
    function decouper(html) {
        var m = /^([\s\S]*?<body\b[^>]*>)([\s\S]*?)(<\/body>[\s\S]*)$/i.exec(html);
        return m ? [m[1], m[2], m[3]] : ['', html, ''];
    }

    function styleBody(avant) {
        var m = /<body\b[^>]*\bstyle\s*=\s*("([^"]*)"|'([^']*)')/i.exec(avant);
        return m ? (m[2] !== undefined ? m[2] : m[3]) : '';
    }

    function charger(etat, valeur) {
        var ed = etat.ed, brut = valeur == null ? '' : String(valeur);
        var parts = estHtml(brut) ? decouper(brut) : ['', texteVersHtml(brut), ''];
        etat.original = brut;
        etat.avant    = parts[0];
        etat.apres    = parts[2];
        ed.setContent(parts[1]);
        ed.getBody().setAttribute('style', styleBody(etat.avant));
        ed.undoManager.clear();
        ed.setDirty(false);
    }

    function lire(etat) {
        if (!etat.ed.isDirty()) return etat.original;
        var corps = etat.ed.getContent();
        return etat.avant ? etat.avant + corps + etat.apres : corps;
    }

    // ── Crochets jQuery : .val() et .prop('readonly') suivent l'éditeur ────────────────────────
    var hookVal = $.valHooks.textarea;
    $.valHooks.textarea = {
        get: function (elem) {
            var etat = etats[elem.id];
            if (etat && etat.pret) return lire(etat);
            return hookVal && hookVal.get ? hookVal.get(elem) : undefined;
        },
        set: function (elem, valeur) {
            var etat = etats[elem.id];
            if (etat && etat.pret) { charger(etat, valeur); return true; }
            return hookVal && hookVal.set ? hookVal.set(elem, valeur) : undefined;
        }
    };

    var hookRo = $.propHooks.readOnly;
    $.propHooks.readOnly = {
        get: hookRo && hookRo.get,
        set: function (elem, valeur, nom) {
            var etat = etats[elem.id];
            if (etat) {
                etat.lecture = !!valeur;
                if (etat.pret) etat.ed.mode.set(etat.lecture ? 'readonly' : 'design');
            }
            return hookRo && hookRo.set ? hookRo.set(elem, valeur, nom) : undefined;
        }
    };

    // ── API ────────────────────────────────────────────────────────────────────────────────────
    window.nijacEditeur = {
        // Remplace le textarea #id par l'éditeur. opts.source : bouton « Code source » (administrateur) ;
        // opts.marqueurs : fonction renvoyant les marqueurs proposés à cet instant (['{NOM}', …]) →
        // menu « Marqueurs » dans la barre d'outils, utilisable aussi en plein écran.
        attacher: function (id, opts) {
            var ta = document.getElementById(id);
            if (!ta || etats[id]) return;
            opts = opts || {};
            var etat = etats[id] = { ed: null, pret: false, lecture: !!ta.readOnly };

            var barre = 'undo redo | blocks | bold italic underline | forecolor backcolor | '
                + 'alignleft aligncenter alignright | bullist numlist | link table | removeformat'
                + (opts.marqueurs ? ' | marqueurs' : '') + ' | fullscreen';
            if (opts.source) barre += ' code';

            tinymce.init({
                target: ta,
                license_key: 'gpl',
                base_url: CONF.base,
                suffix: '.min',
                language: CONF.langue || undefined,
                height: opts.hauteur || 420,
                min_height: 260,
                resize: true,
                menubar: false,
                statusbar: true,
                elementpath: false,
                branding: false,
                promotion: false,
                plugins: 'lists link autolink table code fullscreen',
                fullscreen_native: false, // plein écran dans la fenêtre (Échap ou le bouton pour revenir)
                toolbar: barre,
                toolbar_mode: 'wrap',
                block_formats: 'Paragraphe=p; Titre=h2; Sous-titre=h3',
                browser_spellcheck: true,
                contextmenu: false,
                // Schéma par défaut volontairement gardé : il conserve les attributs des modèles
                // d'email (bgcolor, role, align, cellpadding… vérifié sur la convocation n°3) et
                // retire <script> / gestionnaires on* (un valid_elements '*[*]' les laisserait passer).
                allow_conditional_comments: true,
                protect: PROTEGES,
                entity_encoding: 'raw',
                // Liens et marqueurs ({URL_CONVOCATION_JA}…) gardés tels quels, jamais réécrits.
                convert_urls: false,
                relative_urls: false,
                remove_script_host: false,
                link_default_target: '_blank',
                link_assume_external_targets: 'https',
                // Pas d'images collées en base64 (rejetées par les anti-spam).
                paste_data_images: false,
                // Pas de barre flottante sur les tableaux de mise en page des modèles (menu
                // « Tableau » de la barre d'outils suffisant).
                table_toolbar: '',
                table_default_attributes: { border: '1', cellpadding: '6', cellspacing: '0' },
                table_default_styles: { 'border-collapse': 'collapse' },
                content_style: 'body{font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;margin:10px;}'
                    + 'table{border-collapse:collapse;}',
                setup: function (ed) {
                    etat.ed = ed;
                    if (opts.marqueurs) {
                        ed.ui.registry.addMenuButton('marqueurs', {
                            text: 'Marqueurs',
                            tooltip: 'Insérer un marqueur (remplacé à l\'envoi)',
                            fetch: function (rendre) {
                                var liste = opts.marqueurs() || [];
                                rendre(liste.length
                                    ? liste.map(function (m) {
                                        return { type: 'menuitem', text: m, onAction: function () { ed.insertContent(echapper(m)); } };
                                    })
                                    : [{ type: 'menuitem', text: 'Aucun marqueur pour ce message', enabled: false }]);
                            }
                        });
                    }
                    ed.on('init', function () {
                        charger(etat, ta.value);
                        etat.pret = true;
                        if (etat.lecture) ed.mode.set('readonly');
                    });
                    // Les vues écoutent « input » / « focus » sur le textarea (bouton Aperçu, champ actif).
                    ed.on('input change undo redo SetContent', function () { $(ta).trigger('input'); });
                    ed.on('focus', function () { $(ta).triggerHandler('focus'); });
                }
            });
        },

        // Insère du texte (marqueur) au curseur. Renvoie false si l'éditeur n'est pas actif sur #id.
        inserer: function (id, texte) {
            var etat = etats[id];
            if (!etat || !etat.pret) return false;
            if (!etat.lecture) {
                etat.ed.focus();
                etat.ed.insertContent(echapper(texte));
            }
            return true;
        }
    };
}(window.jQuery, window.tinymce));
