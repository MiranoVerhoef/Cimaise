/**
 * Uploads are sent as they are, so originals and their metadata reach the
 * server intact. The only exception is a file the server would reject: more
 * pixels than its decompression-bomb cap, or more bytes than PHP accepts.
 * Those are downscaled in the browser to fit, instead of failing.
 */

/**
 * Dimensions that fit within maxPixels, keeping the aspect ratio; null when
 * the image already fits (or no limit applies).
 *
 * @param {number} width
 * @param {number} height
 * @param {number} maxPixels
 * @returns {{width: number, height: number}|null}
 */
export function fitDimensions(width, height, maxPixels) {
  if (!(maxPixels > 0) || !(width > 0) || !(height > 0) || width * height <= maxPixels) {
    return null;
  }
  const scale = Math.sqrt(maxPixels / (width * height));
  let w = Math.max(1, Math.floor(width * scale));
  let h = Math.max(1, Math.floor(height * scale));
  // Rounding must never push the result back over the cap.
  while (w * h > maxPixels) {
    if (w >= h) w--; else h--;
  }
  return { width: w, height: h };
}

const encodeQualities = [0.92, 0.85, 0.75];

/**
 * Return the file untouched when the server accepts it, or a downscaled copy.
 *
 * @param {File|Blob} file
 * @param {{maxPixels?: number, maxBytes?: number}} limits
 * @returns {Promise<Blob|null>} null: leave the file as it is.
 */
export async function fitForUpload(file, { maxPixels = 0, maxBytes = 0 } = {}) {
  if (typeof createImageBitmap !== 'function') return null;
  const tooLarge = maxBytes > 0 && file.size > maxBytes;
  let bitmap;
  try {
    bitmap = await createImageBitmap(file);
  } catch (_) {
    return null; // Let the server report what it cannot read.
  }
  try {
    let target = fitDimensions(bitmap.width, bitmap.height, maxPixels);
    if (!target && !tooLarge) return null;
    target = target || { width: bitmap.width, height: bitmap.height };
    const canvas = document.createElement('canvas');
    canvas.width = target.width;
    canvas.height = target.height;
    canvas.getContext('2d').drawImage(bitmap, 0, 0, target.width, target.height);
    const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
    let blob = null;
    for (const quality of encodeQualities) {
      blob = await new Promise(resolve => canvas.toBlob(resolve, type, quality));
      if (!blob || !(maxBytes > 0) || blob.size <= maxBytes || type === 'image/png') break;
    }
    return blob;
  } finally {
    bitmap.close?.();
  }
}
