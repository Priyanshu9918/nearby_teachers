(() => {
    'use strict';
    const sidebar = document.getElementById('admin-sidebar');
    const opener = document.getElementById('open-admin-nav');
    const closer = document.getElementById('close-admin-nav');
    const backdrop = document.getElementById('admin-nav-backdrop');
    const workspace = document.querySelector('.workspace');
    const mobile = window.matchMedia('(max-width: 800px)');
    function toggleNav(open) {
        document.body.classList.toggle('nav-open', open);
        opener?.setAttribute('aria-expanded', String(open));
        if (backdrop) backdrop.hidden = !open;
        if (sidebar) sidebar.inert = mobile.matches && !open;
        if (workspace) workspace.inert = mobile.matches && open;
        if (open) closer?.focus();
        else if (mobile.matches) opener?.focus();
    }
    opener?.addEventListener('click', () => toggleNav(true));
    closer?.addEventListener('click', () => toggleNav(false));
    backdrop?.addEventListener('click', () => toggleNav(false));
    mobile.addEventListener('change', () => {
        document.body.classList.remove('nav-open');
        opener?.setAttribute('aria-expanded', 'false');
        if (backdrop) backdrop.hidden = true;
        if (sidebar) sidebar.inert = mobile.matches;
        if (workspace) workspace.inert = false;
    });
    if (sidebar) sidebar.inert = mobile.matches;
    document.addEventListener('keydown', (event) => {
        if (!document.body.classList.contains('nav-open')) return;
        if (event.key === 'Escape') { toggleNav(false); return; }
        if (event.key !== 'Tab') return;
        const focusable = [...sidebar.querySelectorAll('a[href], button, input, summary')]
            .filter((element) => !element.disabled && element.getClientRects().length);
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    });
    sidebar?.addEventListener('click', (event) => {
        if (mobile.matches && event.target.closest('a[href]')) toggleNav(false);
    });
    const navSearch = document.getElementById('admin-nav-search');
    const originalOpen = new Map();
    const groups = [...document.querySelectorAll('[data-nav-group]')];
    navSearch?.addEventListener('input', () => {
        const term = navSearch.value.trim().toLowerCase();
        let matches = 0;
        document.querySelectorAll('[data-nav-item]').forEach((link) => {
            const groupLabel = link.closest('[data-nav-group]')?.querySelector('summary')?.textContent || '';
            link.hidden = Boolean(term) && !`${link.textContent} ${groupLabel}`.toLowerCase().includes(term);
            if (!link.hidden) matches++;
        });
        groups.forEach((group) => {
            if (term && !originalOpen.has(group)) originalOpen.set(group, group.open);
            group.hidden = ![...group.querySelectorAll('[data-nav-item]')].some((link) => !link.hidden);
            if (term) group.open = !group.hidden;
            else if (originalOpen.has(group)) { group.open = originalOpen.get(group); originalOpen.delete(group); }
        });
        document.querySelectorAll('.nav-section-label').forEach((label) => { label.hidden = Boolean(term); });
        document.getElementById('admin-nav-empty').hidden = matches > 0;
    });
    const reportSearch = document.getElementById('report-library-search');
    reportSearch?.addEventListener('input', () => {
        const term = reportSearch.value.trim().toLowerCase();
        let matches = 0;
        document.querySelectorAll('[data-report-group]').forEach((group) => {
            let visible = 0;
            group.querySelectorAll('[data-report-link]').forEach((link) => {
                link.hidden = !`${group.dataset.label} ${link.textContent}`.toLowerCase().includes(term);
                if (!link.hidden) visible++;
            });
            group.hidden = visible === 0;
            matches += visible;
        });
        document.getElementById('report-library-empty').hidden = matches > 0;
    });
})();
