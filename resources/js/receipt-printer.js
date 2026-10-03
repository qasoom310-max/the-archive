/**
 * POS receipt printing.
 *
 * With a network printer set (POS → Settings → Receipt printer) the receipt
 * is drawn onto a canvas the width of the paper, turned into a 1-bit raster
 * image and POSTed to the Epson printer's ePOS-Print service at its IP — no
 * driver, no print window. Drawing it ourselves rather than sending text is
 * what makes Arabic print correctly: the printer's own fonts cannot join
 * Arabic letters.
 *
 * Without one (or when the printer cannot be reached) the receipt page loads
 * in an off-screen frame and prints itself through the browser, as before.
 */

const FONT = 'system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif';

function loadImage(src) {
    return new Promise((resolve) => {
        if (!src) {
            resolve(null);
            return;
        }
        const img = new Image();
        const timer = setTimeout(() => resolve(null), 4000);
        // A logo the canvas may not read back would block the whole print.
        img.crossOrigin = 'anonymous';
        img.onload = () => { clearTimeout(timer); resolve(img); };
        img.onerror = () => { clearTimeout(timer); resolve(null); };
        img.src = src;
    });
}

function wrap(ctx, text, maxWidth) {
    const words = String(text ?? '').split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';
    for (const word of words) {
        const attempt = line ? `${line} ${word}` : word;
        if (!line || ctx.measureText(attempt).width <= maxWidth) {
            line = attempt;
        } else {
            lines.push(line);
            line = word;
        }
    }
    if (line) lines.push(line);
    return lines.length ? lines : [''];
}

/**
 * Draw a receipt (the JSON the receipt route returns) at `width` dots.
 * Returns the canvas and the rows the logo occupies (dithered, not cut).
 */
async function drawReceipt(data, width) {
    const logo = await loadImage(data.logoUrl);

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = 6000;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, width, canvas.height);
    ctx.fillStyle = '#000';
    ctx.strokeStyle = '#000';
    ctx.textBaseline = 'top';

    const rtl = !!data.rtl;
    ctx.direction = rtl ? 'rtl' : 'ltr';
    const px = (n) => Math.round(n * (width / 576));
    const pad = px(6);
    const start = rtl ? width - pad : pad;
    const end = rtl ? pad : width - pad;
    let y = px(4);
    let logoBand = null;

    const font = (size, weight = 400) => { ctx.font = `${weight} ${px(size)}px ${FONT}`; };

    const center = (text, size, weight = 400) => {
        if (!text) return;
        font(size, weight);
        ctx.textAlign = 'center';
        for (const line of wrap(ctx, text, width - 2 * pad)) {
            ctx.fillText(line, width / 2, y);
            y += px(size * 1.3);
        }
    };

    const row = (left, right, size, weight = 400, indent = 0) => {
        font(size, weight);
        const rightWidth = right ? ctx.measureText(right).width : 0;
        const lines = wrap(ctx, left, width - 2 * pad - rightWidth - px(14) - indent);
        const x = rtl ? start - indent : start + indent;
        ctx.textAlign = 'start';
        lines.forEach((line, i) => ctx.fillText(line, x, y + i * px(size * 1.3)));
        if (right) {
            // Amounts always read left-to-right ("1.60 BD"), even on an Arabic slip.
            ctx.direction = 'ltr';
            ctx.textAlign = rtl ? 'left' : 'right';
            ctx.fillText(right, end, y);
            ctx.direction = rtl ? 'rtl' : 'ltr';
        }
        y += lines.length * px(size * 1.3) + px(4);
    };

    const separator = () => {
        y += px(8);
        ctx.setLineDash([px(10), px(6)]);
        ctx.lineWidth = px(2);
        ctx.beginPath();
        ctx.moveTo(pad, y);
        ctx.lineTo(width - pad, y);
        ctx.stroke();
        ctx.setLineDash([]);
        y += px(14);
    };

    if (logo) {
        const ratio = Math.min(px(360) / logo.width, px(150) / logo.height, 1.5);
        const w = Math.round(logo.width * ratio);
        const h = Math.round(logo.height * ratio);
        logoBand = [y, y + h];
        ctx.drawImage(logo, Math.round((width - w) / 2), y, w, h);
        y += h + px(10);
    }

    const labels = data.labels ?? {};
    center(data.companyName, 36, 800);
    center(`${data.orderReference ?? ''} · ${data.orderedAt ?? ''}`, 23);
    center(data.customerName, 23);
    if (data.customerPhone) center(`${labels.phone ?? ''} \u200E+${data.customerPhone}`, 23);
    center(data.deliveryAddress, 23);
    if (data.deliveryReference) center(`${labels.deliveryRef ?? ''}: ${data.deliveryReference}`, 23);

    separator();
    for (const line of data.lines ?? []) {
        row(`${line.qty}× ${line.name}`, line.total, 27, 600);
        for (const condiment of line.condiments ?? []) {
            row(condiment, '', 22, 400, px(24));
        }
    }

    separator();
    if (data.subtotal) row(labels.subtotal, data.subtotal, 25);
    if (data.taxTotal) row(labels.tax, data.taxTotal, 25);
    if (data.customerDiscount) {
        row(`${labels.customerDiscount} (${data.customerDiscountPercent}%)`, `−${data.customerDiscount}`, 25, 700);
    }
    if (data.deliveryCharge) row(labels.delivery, data.deliveryCharge, 25);
    y += px(4);
    row(labels.total, data.total, 34, 800);
    for (const payment of data.payments ?? []) {
        row(payment.method, payment.amount, 25);
    }
    if (data.changeDue) row(labels.change, data.changeDue, 25, 700);

    y += px(14);
    center(labels.thanks, 25, 700);
    y += px(16);

    const out = document.createElement('canvas');
    out.width = width;
    out.height = Math.ceil(y);
    out.getContext('2d').drawImage(canvas, 0, 0);

    return { canvas: out, logoBand };
}

/** 1-bit raster, MSB first, 1 = black — what ePOS-Print's <image> takes, base64. */
function toRaster(canvas, logoBand) {
    const { width, height } = canvas;
    const pixels = canvas.getContext('2d').getImageData(0, 0, width, height).data;
    const gray = new Float32Array(width * height);
    for (let i = 0; i < width * height; i++) {
        const alpha = pixels[i * 4 + 3] / 255;
        const lum = 0.299 * pixels[i * 4] + 0.587 * pixels[i * 4 + 1] + 0.114 * pixels[i * 4 + 2];
        gray[i] = 255 - alpha * (255 - lum);
    }

    const bytesPerRow = Math.ceil(width / 8);
    const out = new Uint8Array(bytesPerRow * height);
    for (let y = 0; y < height; y++) {
        // The logo is a photo-like image: dither it. Text is cut sharp, and a
        // little dark so thin strokes survive thermal paper.
        const dither = logoBand !== null && y >= logoBand[0] && y < logoBand[1];
        for (let x = 0; x < width; x++) {
            const i = y * width + x;
            const value = gray[i];
            const black = value < (dither ? 128 : 170);
            if (black) out[y * bytesPerRow + (x >> 3)] |= 0x80 >> (x & 7);
            if (dither) {
                const err = value - (black ? 0 : 255);
                if (x + 1 < width) gray[i + 1] += err * 7 / 16;
                if (y + 1 < logoBand[1]) {
                    if (x > 0) gray[i + width - 1] += err * 3 / 16;
                    gray[i + width] += err * 5 / 16;
                    if (x + 1 < width) gray[i + width + 1] += err / 16;
                }
            }
        }
    }

    let binary = '';
    for (let i = 0; i < out.length; i += 0x8000) {
        binary += String.fromCharCode.apply(null, out.subarray(i, i + 0x8000));
    }
    return btoa(binary);
}

async function sendToPrinter(printer, canvas, logoBand) {
    const messages = printer.messages ?? {};
    const xml = '<?xml version="1.0" encoding="utf-8"?>'
        + '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
        + '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">'
        + `<image width="${canvas.width}" height="${canvas.height}" color="color_1" mode="mono">${toRaster(canvas, logoBand)}</image>`
        + '<feed line="3"/><cut type="feed"/>'
        + '</epos-print></s:Body></s:Envelope>';

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    let response;
    try {
        response = await fetch(printer.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'text/xml; charset=utf-8',
                'If-Modified-Since': 'Thu, 01 Jan 1970 00:00:00 GMT',
                SOAPAction: '""',
            },
            body: xml,
            signal: controller.signal,
            // Chrome's local-network access: lets an https page reach the
            // printer on the shop network once the device has allowed it.
            targetAddressSpace: 'local',
        });
    } catch (e) {
        throw new Error(messages.unreachable ?? 'The receipt printer could not be reached.');
    } finally {
        clearTimeout(timer);
    }

    const text = await response.text();
    if (!response.ok || !/success\s*=\s*"true"/.test(text)) {
        const code = (text.match(/code\s*=\s*"([^"]*)"/) || [])[1] || String(response.status);
        throw new Error(`${messages.refused ?? 'The receipt printer refused the job:'} ${code}`);
    }
}

function printInBrowser(url) {
    document.getElementById('pos-receipt-frame')?.remove();
    const frame = document.createElement('iframe');
    frame.id = 'pos-receipt-frame';
    frame.setAttribute('aria-hidden', 'true');
    // Off-screen but full-sized: a zero-sized frame can print blank.
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:420px;height:800px;border:0;';
    frame.src = url;
    document.body.appendChild(frame);
}

/**
 * Print one receipt. `url` is the order's receipt page; `printer` is the
 * network printer config (or null for the browser's own print).
 */
window.printReceipt = async function (url, printer = null) {
    if (printer && printer.url) {
        try {
            const response = await fetch(`${url}?format=json`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const { canvas, logoBand } = await drawReceipt(await response.json(), printer.width || 576);
            await sendToPrinter(printer, canvas, logoBand);
            return;
        } catch (e) {
            console.warn('Network receipt printing failed', e);
            window.alert(`${e.message}\n\n${printer.messages?.fallback ?? ''}`.trim());
        }
    }
    printInBrowser(url);
};

/** The Settings tab's "Print a test page". Rejects with a readable message. */
window.printReceiptTest = async function (printer) {
    const labels = printer.messages?.test ?? {};
    const { canvas, logoBand } = await drawReceipt({
        companyName: labels.title ?? 'Test page',
        orderReference: labels.subtitle ?? '',
        orderedAt: new Date().toLocaleString(),
        rtl: document.documentElement.dir === 'rtl',
        lines: [
            { qty: '1', name: 'Test — اختبار', total: '1.00' },
        ],
        labels: { total: labels.total ?? 'Total', thanks: labels.ok ?? '' },
        subtotal: '', taxTotal: '', total: '1.00',
    }, printer.width || 576);
    await sendToPrinter(printer, canvas, logoBand);
};
