export type SupportType = 'ticket' | 'flyer' | 'affiche';

export type Sector =
  | 'stand'
  | 'evenement'
  | 'parking'
  | 'garage'
  | 'lavage';

export type CreationMode = 'preset' | 'custom';

export interface QrZone {
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface TemplateField {
  key: string;
  label: string;
  placeholder: string;
  required?: boolean;
  type?: 'text' | 'textarea';
  x: number;
  y: number;
  fontSize: number;
  color: string;
  maxWidth?: number;
  fontWeight?: number;
}

export interface TicketTemplate {
  id: string;
  name: string;
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
  qrZone: QrZone;
  values: Record<string, string>;
  qrZip: File | null;
}