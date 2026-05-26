(function () {
    'use strict';

    /*
    form-submission.js

    QUOI : Comportement de la soumission d’événement : Places Autocomplete, actions IA, recadrage d’images (Cropper), jeton reCAPTCHA v3 au submit.

    COMMENT : Callback `initSubmissionMaps` pour Google Places ; POST `/api/ai-action` pour catégorie / reformulation ; modale Cropper et injection du blob dans l’input file ; `grecaptcha.execute` avant envoi du formulaire.

    OÙ : Page publique de proposition d’événement, dépend de `submission.css`, Cropper.js, API Google et config `window.OPALE_CONFIG`.

    POURQUOI : Fluidifier la saisie (adresse, médias) tout en limitant le spam et les abus via rate limiting côté serveur et score reCAPTCHA.
    */

    const form = document.querySelector('.submission-form');
    if (!form) return;

    const config = window.OPALE_CONFIG || {};

    window.initSubmissionMaps = function () {
        const input = document.querySelector('[data-places-autocomplete]');
        if (!input || !window.google?.maps?.places) return;

        const autocomplete = new google.maps.places.Autocomplete(input, {
            componentRestrictions: { country: 'fr' },
            fields: ['formatted_address', 'address_components', 'geometry'],
            types: ['geocode'],
        });

        autocomplete.addListener('place_changed', () => {
            const place = autocomplete.getPlace();
            if (!place.geometry) return;

            if (place.formatted_address) {
                input.value = place.formatted_address;
            }

            const findValue = (type) => {
                const c = (place.address_components || []).find((c) => c.types.includes(type));
                return c ? c.long_name : null;
            };

            const villeInput = document.getElementById('event_submission_ville');
            const cpInput = document.getElementById('event_submission_codePostal');
            const latInput = document.getElementById('event_submission_latitude');
            const lngInput = document.getElementById('event_submission_longitude');

            const ville = findValue('locality') || findValue('postal_town');
            const cp = findValue('postal_code');

            if (villeInput && ville) villeInput.value = ville;
            if (cpInput && cp) cpInput.value = cp;
            if (latInput) latInput.value = place.geometry.location.lat().toFixed(6);
            if (lngInput) lngInput.value = place.geometry.location.lng().toFixed(6);
        });
    };

    const $titre = document.getElementById('event_submission_titre');
    const $desc = document.getElementById('event_submission_description');
    const $cat = document.getElementById('event_submission_categorie');

    function showAiNotice(message, type = 'info') {
        const existing = document.querySelector('.ai-notice');
        if (existing) existing.remove();
        const notice = document.createElement('div');
        notice.className = `ai-notice ai-notice--${type}`;
        notice.textContent = message;
        const target = document.querySelector('.form-actions-inline') || form;
        target.appendChild(notice);
        setTimeout(() => notice.remove(), 6000);
    }

    async function callAI(action, payload, button) {
        if (!button) return null;
        const originalText = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span aria-hidden="true">⏳</span> En cours…';

        try {
            const response = await fetch('/api/ai-action', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ...payload }),
            });

            const data = await response.json().catch(() => ({}));

            if (response.status === 429) {
                showAiNotice(data.error || 'Trop de demandes IA, attendez un instant.', 'warning');
                return null;
            }
            if (response.status === 503) {
                showAiNotice(data.error || 'L\'IA est saturée, réessayez dans 1-2 minutes.', 'warning');
                return null;
            }
            if (!response.ok) {
                showAiNotice(data.error || `Erreur ${response.status}.`, 'error');
                return null;
            }
            if (!data.result) {
                showAiNotice('Réponse vide de l\'IA.', 'error');
                return null;
            }
            return data.result;
        } catch (err) {
            showAiNotice('Impossible de joindre le serveur IA.', 'error');
            return null;
        } finally {
            button.disabled = false;
            button.innerHTML = originalText;
        }
    }

    document.getElementById('btn-suggest-category')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const titre = ($titre?.value || '').trim();
        const desc = ($desc?.value || '').trim();
        if (!titre && !desc) {
            alert('Remplissez d\'abord le titre et/ou la description.');
            return;
        }
        const result = await callAI('suggest_category', { titre, description: desc }, btn);
        if (result && $cat) {
            const exists = Array.from($cat.options).some((o) => o.value === result);
            $cat.value = exists ? result : 'Autre';
        }
    });

    document.getElementById('btn-improve-desc')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const text = ($desc?.value || '').trim();
        if (text.length < 20) {
            alert('Écrivez au moins une phrase complète à améliorer.');
            return;
        }
        const result = await callAI('improve', { text }, btn);
        if (result && $desc) $desc.value = result;
    });

    document.getElementById('btn-correct-desc')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const text = ($desc?.value || '').trim();
        if (text.length === 0) {
            alert('Pas de texte à corriger.');
            return;
        }
        const result = await callAI('correct', { text }, btn);
        if (result && $desc) $desc.value = result;
    });

    const modal = document.getElementById('cropper-modal');
    const cropImg = document.getElementById('cropper-image');
    const btnConfirm = document.getElementById('cropper-confirm');
    const btnCancel = document.getElementById('cropper-cancel');

    let activeCropper = null;
    let activeContext = null;

    function openCropper(file, container) {
        if (!window.Cropper) {
            alert('Cropper non disponible, l\'image sera envoyée sans recadrage.');
            return;
        }

        const ratioW = parseInt(container.dataset.ratioW, 10);
        const ratioH = parseInt(container.dataset.ratioH, 10);
        const outW = parseInt(container.dataset.outputW, 10);
        const outH = parseInt(container.dataset.outputH, 10);

        cropImg.src = URL.createObjectURL(file);
        modal.showModal();

        cropImg.onload = () => {
            if (activeCropper) activeCropper.destroy();
            activeCropper = new Cropper(cropImg, {
                aspectRatio: ratioW / ratioH,
                viewMode: 1,
                autoCropArea: 1,
                background: false,
            });
            activeContext = { container, file, outW, outH };
        };
    }

    btnConfirm?.addEventListener('click', () => {
        if (!activeCropper || !activeContext) return;

        activeCropper.getCroppedCanvas({
            width: activeContext.outW,
            height: activeContext.outH,
            imageSmoothingQuality: 'high',
        }).toBlob((blob) => {
            if (!blob) return;
            const ext = activeContext.file.name.match(/\.(\w+)$/)?.[1] || 'jpg';
            const croppedFile = new File([blob], `cropped.${ext}`, { type: blob.type });

            const dt = new DataTransfer();
            dt.items.add(croppedFile);
            const fileInput = activeContext.container.querySelector('input[type="file"]');
            fileInput.files = dt.files;

            const preview = activeContext.container.querySelector('[data-preview]');
            if (preview) {
                preview.innerHTML = '';
                const img = document.createElement('img');
                img.src = URL.createObjectURL(blob);
                preview.appendChild(img);
            }

            activeCropper.destroy();
            activeCropper = null;
            modal.close();
        }, 'image/jpeg', 0.88);
    });

    btnCancel?.addEventListener('click', () => {
        if (activeCropper) activeCropper.destroy();
        activeCropper = null;
        if (activeContext) {
            activeContext.container.querySelector('input[type="file"]').value = '';
        }
        modal.close();
    });

    document.querySelectorAll('.image-uploader').forEach((container) => {
        const input = container.querySelector('input[type="file"]');
        if (!input) return;

        input.addEventListener('change', (e) => {
            const file = e.target.files?.[0];
            if (!file) return;
            if (file.size > 8 * 1024 * 1024) {
                alert('Fichier trop volumineux (max 8 Mo).');
                input.value = '';
                return;
            }
            openCropper(file, container);
        });
    });

    form.addEventListener('submit', (e) => {
        if (!config.recaptchaSiteKey) return;

        const tokenField = document.getElementById('g-recaptcha-response');
        if (tokenField.value) return;

        // Sans consentement, reCAPTCHA n'a pas été injecté : on bloque + on rouvre le bandeau
        if (!window.OpaleConsent || !window.OpaleConsent.isAccepted()) {
            e.preventDefault();
            alert('Pour soumettre un événement, vous devez accepter les cookies tiers (reCAPTCHA anti-spam).');
            document.body.setAttribute('data-consent', 'pending');
            const banner = document.getElementById('cookie-banner');
            if (banner) banner.hidden = false;
            return;
        }

        if (!window.grecaptcha) {
            // Consentement donné mais script pas encore arrivé
            e.preventDefault();
            alert('Protection anti-spam en cours de chargement, réessayez dans un instant.');
            return;
        }

        e.preventDefault();
        grecaptcha.ready(() => {
            grecaptcha.execute(config.recaptchaSiteKey, { action: 'submit_event' }).then((token) => {
                tokenField.value = token;
                form.submit();
            }).catch(() => {
                alert('Échec de la vérification reCAPTCHA. Réessayez.');
            });
        });
    });
})();
