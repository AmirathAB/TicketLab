import { useRef } from 'react';
import type { PointerEvent } from 'react';
import type { ImageSize, QrZone } from '../../types/ticketlab';
import { MIN_QR_SIZE, clampZone } from '../../utils/qrZone';

type Corner = 'nw' | 'ne' | 'sw' | 'se';

type Gesture =
  | { kind: 'move'; startX: number; startY: number; origin: QrZone }
  | { kind: 'resize'; corner: Corner; origin: QrZone }
  | { kind: 'draw'; startX: number; startY: number; moved: boolean };

interface Props {
  /** Zone en pixels NATIFS de l'image */
  zone: QrZone;
  /** Taille native de l'image : référence de toutes les coordonnées */
  image: ImageSize;
  onChange: (zone: QrZone) => void;
}

/**
 * Éditeur interactif de la zone QR (mode "template personnel").
 *
 *  - glisser la zone         : la déplacer
 *  - glisser une poignée     : la redimensionner (toujours carrée)
 *  - glisser dans le vide    : dessiner une nouvelle zone
 *  - cliquer dans le vide    : recentrer la zone à cet endroit
 *
 * Tout est calculé en pixels natifs de l'image (et non en pixels d'écran),
 * donc les coordonnées envoyées au serveur correspondent à l'image réelle,
 * quelle que soit la taille d'affichage.
 */
export default function QrZoneEditor({ zone, image, onChange }: Props) {
  const layerRef = useRef<HTMLDivElement>(null);
  const gesture = useRef<Gesture | null>(null);

  /** Position du pointeur en pixels natifs. */
  function toImage(event: PointerEvent): { x: number; y: number } {
    const rect = layerRef.current!.getBoundingClientRect();

    return {
      x: ((event.clientX - rect.left) / rect.width) * image.width,
      y: ((event.clientY - rect.top) / rect.height) * image.height,
    };
  }

  function begin(event: PointerEvent, next: Gesture) {
    event.stopPropagation();
    layerRef.current?.setPointerCapture(event.pointerId);
    gesture.current = next;
  }

  function onZoneDown(event: PointerEvent) {
    const point = toImage(event);
    begin(event, { kind: 'move', startX: point.x, startY: point.y, origin: zone });
  }

  function onHandleDown(event: PointerEvent, corner: Corner) {
    begin(event, { kind: 'resize', corner, origin: zone });
  }

  function onLayerDown(event: PointerEvent) {
    const point = toImage(event);
    begin(event, { kind: 'draw', startX: point.x, startY: point.y, moved: false });
  }

  function onMove(event: PointerEvent) {
    const current = gesture.current;
    if (!current) return;

    const point = toImage(event);

    if (current.kind === 'move') {
      onChange(
        clampZone(
          {
            ...current.origin,
            x: current.origin.x + (point.x - current.startX),
            y: current.origin.y + (point.y - current.startY),
          },
          image,
        ),
      );
      return;
    }

    if (current.kind === 'resize') {
      // Le coin opposé reste fixe, la zone reste carrée
      const { origin, corner } = current;
      const anchorX = corner.includes('w') ? origin.x + origin.width : origin.x;
      const anchorY = corner.includes('n') ? origin.y + origin.height : origin.y;

      const maxW = corner.includes('w') ? anchorX : image.width - anchorX;
      const maxH = corner.includes('n') ? anchorY : image.height - anchorY;
      const size = Math.round(
        Math.min(
          Math.max(MIN_QR_SIZE, Math.max(Math.abs(point.x - anchorX), Math.abs(point.y - anchorY))),
          maxW,
          maxH,
        ),
      );

      onChange({
        width: size,
        height: size,
        x: corner.includes('w') ? anchorX - size : anchorX,
        y: corner.includes('n') ? anchorY - size : anchorY,
      });
      return;
    }

    // draw : carré ancré au point de départ
    const dx = point.x - current.startX;
    const dy = point.y - current.startY;
    const size = Math.max(Math.abs(dx), Math.abs(dy));

    if (size < 4) return;
    current.moved = true;

    onChange(
      clampZone(
        {
          width: size,
          height: size,
          x: dx < 0 ? current.startX - size : current.startX,
          y: dy < 0 ? current.startY - size : current.startY,
        },
        image,
      ),
    );
  }

  function onUp(event: PointerEvent) {
    const current = gesture.current;
    gesture.current = null;
    layerRef.current?.releasePointerCapture(event.pointerId);

    // Simple clic dans le vide : on recentre la zone à cet endroit
    if (current?.kind === 'draw' && !current.moved) {
      onChange(
        clampZone(
          {
            ...zone,
            x: current.startX - zone.width / 2,
            y: current.startY - zone.height / 2,
          },
          image,
        ),
      );
    }
  }

  const pct = {
    left: `${(zone.x / image.width) * 100}%`,
    top: `${(zone.y / image.height) * 100}%`,
    width: `${(zone.width / image.width) * 100}%`,
    height: `${(zone.height / image.height) * 100}%`,
  };

  const corners: Corner[] = ['nw', 'ne', 'sw', 'se'];

  return (
    <div
      ref={layerRef}
      className="qr-editor"
      onPointerDown={onLayerDown}
      onPointerMove={onMove}
      onPointerUp={onUp}
      onPointerCancel={onUp}
    >
      <div className="qr-editor__zone" style={pct} onPointerDown={onZoneDown}>
        <span className="qr-editor__label">
          QR · {zone.width} × {zone.height}
        </span>
        {corners.map((corner) => (
          <i
            key={corner}
            className={`qr-editor__handle qr-editor__handle--${corner}`}
            onPointerDown={(event) => onHandleDown(event, corner)}
          />
        ))}
      </div>
    </div>
  );
}
