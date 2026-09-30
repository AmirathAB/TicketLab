import type { ImageSize, QrZone } from '../types/ticketlab';

/** Taille minimale d'une zone QR (px natifs). */
export const MIN_QR_SIZE = 20;

/** Garde la zone entièrement dans l'image. */
export function clampZone(zone: QrZone, image: ImageSize): QrZone {
  const width = Math.min(Math.max(MIN_QR_SIZE, Math.round(zone.width)), image.width);
  const height = Math.min(Math.max(MIN_QR_SIZE, Math.round(zone.height)), image.height);

  return {
    width,
    height,
    x: Math.min(Math.max(0, Math.round(zone.x)), image.width - width),
    y: Math.min(Math.max(0, Math.round(zone.y)), image.height - height),
  };
}

/** Zone carrée par défaut (~20 % du plus petit côté), centrée. */
export function defaultZone(image: ImageSize): QrZone {
  const size = Math.max(MIN_QR_SIZE, Math.round(Math.min(image.width, image.height) * 0.2));

  return clampZone(
    {
      width: size,
      height: size,
      x: (image.width - size) / 2,
      y: (image.height - size) / 2,
    },
    image,
  );
}
