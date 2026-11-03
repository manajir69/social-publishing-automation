(() => {
    const brand = document.querySelector('#folder-branding-settings');
    if (!brand) return;
    const control = name => brand.querySelector(`[name="folder_${name}"]`);
    const preview = brand.querySelector('#branding-preview');
    const text = brand.querySelector('#branding-text-preview');
    const logo = brand.querySelector('#branding-logo-preview');
    const warning = document.createElement('p');
    warning.className = 'branding-preview-warning';
    warning.setAttribute('role', 'status');
    preview.after(warning);
    let coverage = null, logoUrl = null;
    const fonts = {'NotoSansBengali-Regular.ttf':'BrandingText','NotoSansSymbols2-Regular.ttf':'BrandingSymbols','NotoEmoji.ttf':'BrandingEmoji'};
    const rgba = (hex, opacity) => {
        const value = /^#[0-9a-f]{6}$/i.test(hex) ? hex : '#050f19';
        return `rgba(${parseInt(value.slice(1,3),16)},${parseInt(value.slice(3,5),16)},${parseInt(value.slice(5,7),16)},${opacity})`;
    };
    // HEX is an editable companion; the named color input remains the saved value.
    brand.querySelectorAll('[data-color-for]').forEach(hex => {
        const picker = brand.querySelector(`[name="${hex.dataset.colorFor}"]`);
        picker.addEventListener('input', () => {hex.value = picker.value.toUpperCase(); hex.setCustomValidity('');});
        hex.addEventListener('input', () => {
            const value = hex.value.trim();
            const normalized = value.startsWith('#') ? value : '#' + value;
            const valid = /^#[0-9a-f]{6}$/i.test(normalized);
            hex.setCustomValidity(valid ? '' : 'Enter a six-digit HEX color, such as #FFFFFF.');
            if (valid) {hex.value = normalized.toUpperCase(); picker.value = normalized;}
        });
    });
    const refresh = () => {
        if (logo) {
            logo.style.width = `${control('logo_size_pct').value}%`;
            logo.dataset.position = control('logo_position').value;
            logo.style.visibility = control('branding_enabled').checked && !brand.querySelector('[name="remove_folder_logo"]')?.checked ? 'visible' : 'hidden';
        }
        text.replaceChildren();
        warning.textContent = '';
        text.dataset.position = control('text_position').value;
        text.style.visibility = control('branding_enabled').checked ? 'visible' : 'hidden';
        const lines = control('brand_text').value.split(/\r\n|[\n\r\u0085\u2028\u2029]/).map(line => line.trim()).filter(Boolean);
        const foreground = rgba(control('text_color').value, Number(control('text_opacity').value)/100);
        const background = control('text_background_enabled').checked ? rgba(control('text_background_color').value, Number(control('text_background_opacity').value)/100) : 'transparent';
        for (const line of lines) {
            const pill = document.createElement('div');
            pill.className = 'branding-pill';
            pill.style.color = foreground;
            pill.style.borderColor = foreground;
            pill.style.background = background;
            // Paint only the inner area: translucent border must stay independent.
            pill.style.backgroundClip = 'padding-box';
            let last = null, previousFont = null;
            for (const char of line) {
                const point = char.codePointAt(0);
                if (point === 0xfe0e || point === 0xfe0f) continue;
                const font = coverage && Object.keys(coverage).find(key => coverage[key].some(([a,b]) => point >= a && point <= b));
                if (coverage && (!font || point === 0x200d)) warning.textContent = `Unsupported character U+${point.toString(16).toUpperCase()}. Use a standalone Unicode icon such as ☎, ✆, ◎, 🌐, 📞 or 📍.`;
                if (!last || previousFont !== font) {
                    last = document.createElement('span');
                    if (font) last.style.fontFamily = fonts[font];
                    pill.append(last); previousFont = font;
                }
                last.append(document.createTextNode(char));
            }
            text.append(pill);
        }
        // Match the renderer's proportions using a 1000px-wide reference image.
        const scale = preview.clientWidth / 1000;
        const virtualHeight = preview.clientHeight / scale;
        let size = Math.min(120, Math.min(1000,virtualHeight)*Number(control('text_size_pct').value)/100);
        for (;;) {
            text.style.fontSize = `${size*scale}px`;
            text.style.gap = `${Math.max(1,Math.round(size*.3))*scale}px`;
            for (const pill of text.children) {
                pill.style.padding = `${Math.max(1,Math.round(size*.35))*scale}px ${Math.max(2,Math.round(size*.65))*scale}px`;
                pill.style.borderWidth = `${Math.max(1,Math.round(size*.045))*scale}px`;
            }
            const pills = [...text.children];
            const rowWidth = pills.reduce((sum, pill) => sum + pill.getBoundingClientRect().width, 0) + Math.max(0, pills.length - 1) * Math.max(1,Math.round(size*.3))*scale;
            const fits = rowWidth <= preview.clientWidth*.88 && text.getBoundingClientRect().height <= preview.clientHeight*.93;
            if (fits || size <= 1) break;
            size = Math.max(1,size-.5);
        }
    };
    brand.querySelectorAll('input, select, textarea').forEach(input => input.addEventListener('input', refresh));
    brand.querySelector('#folder-logo-input')?.addEventListener('change', event => {
        const file = event.target.files?.[0];
        if (!file || !logo) return;
        if (logoUrl) URL.revokeObjectURL(logoUrl);
        logoUrl = URL.createObjectURL(file);
        logo.src = logoUrl; logo.hidden = false; refresh();
    });
    new ResizeObserver(refresh).observe(preview);
    refresh();
    Promise.all([
        fetch('assets/branding-fonts/coverage.json').then(response => {if (!response.ok) throw new Error(); return response.json();}),
        ...Object.values(fonts).map(font => document.fonts.load(`16px ${font}`))
    ]).then(([map]) => {coverage = map; refresh();}).catch(() => {warning.textContent = 'Preview fonts could not load. Refresh after uploading all branding files.';});
})();
