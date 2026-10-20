import QRCode from 'qrcode';

window.renderQrCode = function (canvas, text) {
    QRCode.toCanvas(canvas, text, { width: 220, margin: 1 }, function (error) {
        if (error) console.error(error);
    });
};

/**
 * navigator.clipboard only exists in secure contexts (HTTPS, or
 * http://localhost) — this panel is currently deployed on a bare IP over
 * plain HTTP, where that API is simply undefined, so every "复制" button
 * would silently no-op. Falls back to the old execCommand('copy') trick
 * (deprecated but still supported everywhere) via a temporary offscreen
 * textarea whenever the modern API isn't available.
 */
window.copyToClipboard = function (text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    try {
        document.execCommand('copy');
    } catch (error) {
        console.error(error);
    }
    document.body.removeChild(textarea);
    return Promise.resolve();
};
