export type SupportType = 'ticket' | 'flyer' | 'affiche';

export type Sector =
  | 'stand'
  | 'evenement'
  | 'parking'
  | 'garage'
  | 'lavage';

export type CreationMode = 'preset' | 'custom';

/** Coordonnées en pixels, dans la résolution NATIVE de l'image. */
export interface QrZone {
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface ImageSize {
  width: number;
  height: number;
}

export interface TemplateField {
  key: string;
  label: string;
  placeholder: string;
  required?: boolean;
  /** `counter` : numéro incrémenté automatiquement par ticket (non éditable) */
  type?: 'text' | 'textarea' | 'counter';
  x: number;
  y: number;
  fontSize: number;
  color: string;
  maxWidth?: number;
  fontWeight?: number;
  fontFamily?: string;
  lineHeight?: number;
  align?: 'left' | 'center' | 'right';
}

export interface TicketTemplate {
  id: number;
  name: string;
  description?: string | null;
  type: SupportType;
  sector: Sector;
  imagePath: string;
  width: number;
  height: number;
  qrZone: QrZone;
  fields: TemplateField[];
}

export interface GeneratorState {
  support: SupportType | null;
  sector: Sector | null;
  mode: CreationMode | null;
  template: TicketTemplate | null;
  customTemplateFile: File | null;
  customTemplatePreview: string | null;
  customImageSize: ImageSize | null;
  qrZone: QrZone;
  values: Record<string, string>;
  qrZip: File | null;
  qrCount: number | null;
}
