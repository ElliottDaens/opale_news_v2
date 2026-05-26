(function () {
    'use strict';

    /*
    motion.js

    QUOI : Comportements UX globaux de la page publique : animations au scroll, ombre du header, API `window.opaleReveal` pour contenu injecté.

    COMMENT : `IntersectionObserver` révèle `[data-reveal]` et grilles enfants ; respect de `prefers-reduced-motion` ; écoute passive du scroll pour la classe `is-scrolled` sur `.site-header`.

    OÙ : Chargé avec les vues front ; consommé aussi par le JS de recherche après injection des cartes.

    POURQUOI : Donner du relief sans JS lourd et resynchroniser les animations lorsque le DOM est mis à jour dynamiquement.
    */

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!prefersReducedMotion && 'IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.08,
            rootMargin: '0px 0px -60px 0px',
        });

        document.querySelectorAll('[data-reveal], [data-reveal-children]').forEach((el) => {
            observer.observe(el);
        });
    } else {
        document.querySelectorAll('[data-reveal], [data-reveal-children]').forEach((el) => {
            el.classList.add('is-revealed');
        });
    }

    const header = document.querySelector('.site-header');
    if (header) {
        let ticking = false;
        const onScroll = () => {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(() => {
                header.classList.toggle('is-scrolled', window.scrollY > 8);
                ticking = false;
            });
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    window.opaleReveal = (root = document) => {
        if (prefersReducedMotion) {
            root.querySelectorAll('[data-reveal], [data-reveal-children]').forEach((el) => {
                el.classList.add('is-revealed');
            });
            return;
        }
        const obs = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    obs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.05, rootMargin: '0px 0px -30px 0px' });

        root.querySelectorAll('[data-reveal], [data-reveal-children]').forEach((el) => {
            obs.observe(el);
        });
    };
})();
