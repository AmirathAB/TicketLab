import { useState, useEffect } from 'react';
import type { ChangeEvent } from 'react';
import JSZip from 'jszip';
import {
  AlertTriangle,
  ArrowLeft,
  Car,
  FileText,
  Image as ImageIcon,
  Loader2,
  PartyPopper,
  PenLine,
  Puzzle,
  QrCode,
  Sparkles,
  Store,
  Ticket,
  Upload,
  Wrench,
  CircleParking,
  Crosshair,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import './App.css';
import apiClient, {
  TOKEN_KEY,
  UNAUTHORIZED_EVENT,
  getErrorMessage,
} from './api/client';
import QrZoneEditor from './components/editor/QrZoneEditor';
import { clampZone, defaultZone } from './utils/qrZone';
import type {
  CreationMode,
  GeneratorState,
  QrZone,
  Sector,
  SupportType,
  TemplateField,
  TicketTemplate,
} from './types/ticketlab';

// Limites affichées/validées côté client (le serveur revalide de toute façon)
const MAX_TEMPLATE_MB = 10;
const MAX_QR_ZIP_MB = 50;
const QR_SIZES = [150, 200, 250];

// Forme JSON renvoyée par GET /api/templates
interface ApiTemplate extends Omit<TicketTemplate, 'imagePath' | 'qrZone'> {
  image_url: string;
  qr_zone: QrZone;
}

const initialQrZone: QrZone = {
  x: 650,
  y: 220,
  width: 220,
  height: 220,
};

const initialState: GeneratorState = {
  support: null,
  sector: null,
  mode: null,
  template: null,
  customTemplateFile: null,
  customTemplatePreview: null,
  customImageSize: null,
  qrZone: initialQrZone,
  values: {},
  qrZip: null,
  qrCount: null,
};

const supportChoices: Array<{
  value: SupportType;
  title: string;
  description: string;
  icon: LucideIcon;
}> = [
  {
    value: 'ticket',
    title: 'Ticket',
    description: "Coupons, accès, bons d'achat et prestations.",
    icon: Ticket,
  },
  {
    value: 'flyer',
    title: 'Flyer',
    description: 'Supports promotionnels à distribuer.',
    icon: FileText,
  },
  {
    value: 'affiche',
    title: 'Affiche',
    description: 'Visuels grand format pour informer.',
    icon: ImageIcon,
  },
];

const sectorChoices: Array<{
  value: Sector;
  title: string;
  icon: LucideIcon;
}> = [
  { value: 'stand', title: 'Stand', icon: Store },
  { value: 'evenement', title: 'Événement', icon: PartyPopper },
  { value: 'parking', title: 'Parking', icon: CircleParking },
  { value: 'garage', title: 'Garage', icon: Wrench },
  { value: 'lavage', title: 'Lavage', icon: Car },
];

function App() {
  // Session conservée au rafraîchissement : on vérifie le token stocké via /me
  const [isCheckingSession, setIsCheckingSession] = useState(
    () => Boolean(localStorage.getItem(TOKEN_KEY)),
  );
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [isLoggingIn, setIsLoggingIn] = useState(false);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loginError, setLoginError] = useState('');
  const [step, setStep] = useState(1);
  const [state, setState] = useState<GeneratorState>(initialState);
  const [isGenerating, setIsGenerating] = useState(false);
  const [successMessage, setSuccessMessage] = useState('');
  const [errorMessage, setErrorMessage] = useState('');
  const [uploadProgress, setUploadProgress] = useState<number | null>(null);
  const [availableTemplates, setAvailableTemplates] = useState<TicketTemplate[]>([]);
  const [isLoadingTemplates, setIsLoadingTemplates] = useState(false);

  useEffect(() => {
    if (!localStorage.getItem(TOKEN_KEY)) {
      return;
    }

    apiClient
      .get('/me')
      .then(() => setIsAuthenticated(true))
      .catch(() => setIsAuthenticated(false))
      .finally(() => setIsCheckingSession(false));
  }, []);

  // Token expiré ou révoqué pendant l'utilisation
  useEffect(() => {
    function onUnauthorized() {
      setIsAuthenticated(false);
      setLoginError('Votre session a expiré. Reconnectez-vous.');
    }

    window.addEventListener(UNAUTHORIZED_EVENT, onUnauthorized);
    return () => window.removeEventListener(UNAUTHORIZED_EVENT, onUnauthorized);
  }, []);

  async function loadTemplates() {
    setIsLoadingTemplates(true);
    try {
      const response = await apiClient.get('/templates', {
        params: {
          type: state.support,
          sector: state.sector,
        },
      });

      const templates = response.data.map((t: ApiTemplate) => ({
        id: t.id,
        type: t.type,
        sector: t.sector,
        name: t.name,
        description: t.description,
        imagePath: t.image_url,
        width: t.width,
        height: t.height,
        qrZone: t.qr_zone,
        fields: t.fields,
      }));

      setAvailableTemplates(templates);
    } catch (error) {
      console.error('Erreur lors du chargement des templates:', error);
      setAvailableTemplates([]);
      setErrorMessage(await getErrorMessage(error, 'Impossible de charger les templates.'));
    } finally {
      setIsLoadingTemplates(false);
    }
  }

  useEffect(() => {
    if (isAuthenticated && state.support && state.sector) {
      loadTemplates();
    }
    // loadTemplates lit support/sector : ils sont déjà dans les dépendances
    // oxlint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthenticated, state.support, state.sector]);

  const selectedTemplateImage =
    state.mode === 'preset'
      ? state.template?.imagePath ?? null
      : state.customTemplatePreview;

  // Dimensions NATIVES de l'image affichée : toutes les coordonnées (champs,
  // zone QR) sont exprimées dans cette résolution, jamais en pixels d'écran.
  const canvasWidth =
    state.mode === 'preset'
      ? state.template?.width ?? 1000
      : state.customImageSize?.width ?? 1000;

  const canvasHeight =
    state.mode === 'preset'
      ? state.template?.height ?? 650
      : state.customImageSize?.height ?? 650;

  const activeQrZone =
    state.mode === 'preset' && state.template
      ? state.template.qrZone
      : state.qrZone;

  const fields =
    state.mode === 'preset' && state.template ? state.template.fields : [];

  // Les champs `counter` (numéro de ticket) sont remplis automatiquement
  const editableFields = fields.filter((field) => field.type !== 'counter');

  function updateState(patch: Partial<GeneratorState>) {
    setState((current) => ({ ...current, ...patch }));
    setSuccessMessage('');
    setErrorMessage('');
  }

  async function login(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!email.trim() || !password) {
      setLoginError('Renseigne ton email et ton mot de passe.');
      return;
    }

    setIsLoggingIn(true);

    try {
      const response = await apiClient.post('/login', {
        email: email.trim(),
        password,
      });

      localStorage.setItem(TOKEN_KEY, response.data.token);

      setLoginError('');
      setPassword('');
      setIsAuthenticated(true);
    } catch (error) {
      setLoginError(await getErrorMessage(error, 'Erreur de connexion'));
    } finally {
      setIsLoggingIn(false);
    }
  }

  function chooseSupport(support: SupportType) {
    updateState({
      support,
      sector: null,
      mode: null,
      template: null,
      values: {},
    });
    setStep(2);
  }

  function chooseSector(sector: Sector) {
    updateState({
      sector,
      mode: null,
      template: null,
      values: {},
    });
    setStep(3);
  }

  function chooseMode(mode: CreationMode) {
    updateState({
      mode,
      template: null,
      values: {},
    });
    setStep(4);
  }

  function selectTemplate(template: TicketTemplate) {
    const values = template.fields.reduce<Record<string, string>>(
      (result, field) => {
        result[field.key] = field.placeholder;
        return result;
      },
      {},
    );

    updateState({
      template,
      values,
      qrZone: template.qrZone,
    });

    setStep(5);
  }

  function handleCustomTemplateUpload(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = '';

    if (!file) {
      return;
    }

    if (!['image/jpeg', 'image/png'].includes(file.type)) {
      setErrorMessage('Utilisez uniquement une image PNG ou JPG.');
      return;
    }

    if (file.size > MAX_TEMPLATE_MB * 1024 * 1024) {
      setErrorMessage(`L'image dépasse ${MAX_TEMPLATE_MB} Mo.`);
      return;
    }

    const reader = new FileReader();

    reader.onload = () => {
      const preview = String(reader.result);
      const probe = new Image();

      probe.onload = () => {
        // La zone QR vit dans la résolution réelle de l'image
        const size = { width: probe.naturalWidth, height: probe.naturalHeight };

        updateState({
          customTemplateFile: file,
          customTemplatePreview: preview,
          customImageSize: size,
          qrZone: defaultZone(size),
        });
        setStep(5);
      };

      probe.onerror = () => setErrorMessage("Cette image est illisible ou corrompue.");
      probe.src = preview;
    };

    reader.readAsDataURL(file);
  }

  function updateField(field: TemplateField, value: string) {
    updateState({
      values: {
        ...state.values,
        [field.key]: value,
      },
    });
  }

  function updateQrZone(zone: QrZone) {
    updateState({ qrZone: zone });
  }

  function updateQrZoneFromInputs(key: keyof QrZone, value: string) {
    const parsedValue = Number(value);

    if (!Number.isFinite(parsedValue) || !state.customImageSize) {
      return;
    }

    updateQrZone(
      clampZone({ ...state.qrZone, [key]: parsedValue }, state.customImageSize),
    );
  }

  async function handleQrZipUpload(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = '';

    if (!file) {
      return;
    }

    if (!file.name.toLowerCase().endsWith('.zip')) {
      setErrorMessage('Sélectionnez un fichier ZIP qui contient les QR codes.');
      return;
    }

    if (file.size > MAX_QR_ZIP_MB * 1024 * 1024) {
      setErrorMessage(`Le ZIP dépasse ${MAX_QR_ZIP_MB} Mo.`);
      return;
    }

    // Inspection locale : compte les images pour annoncer le nombre de tickets
    try {
      const archive = await JSZip.loadAsync(file);
      const count = Object.values(archive.files).filter(
        (entry) =>
          !entry.dir &&
          !entry.name.includes('__MACOSX/') &&
          /\.(png|jpe?g)$/i.test(entry.name),
      ).length;

      if (count === 0) {
        setErrorMessage('Ce ZIP ne contient aucune image PNG ou JPG.');
        return;
      }

      updateState({ qrZip: file, qrCount: count });
    } catch {
      setErrorMessage('Ce fichier ZIP est invalide ou corrompu.');
    }
  }

  function requiredFieldsCompleted() {
    return fields
      .filter((field) => field.required)
      .every((field) => state.values[field.key]?.trim());
  }

  function canGenerate() {
    const hasDesign =
      state.mode === 'preset'
        ? Boolean(state.template)
        : Boolean(state.customTemplatePreview);

    const hasQrZip = Boolean(state.qrZip);
    const hasRequiredFields =
      state.mode === 'preset' ? requiredFieldsCompleted() : true;

    return hasDesign && hasQrZip && hasRequiredFields;
  }

  async function generateTickets() {
    if (!canGenerate()) {
      setErrorMessage(
        'Complétez les informations obligatoires et ajoutez le ZIP des QR codes.',
      );
      return;
    }

    setIsGenerating(true);
    setSuccessMessage('');
    setErrorMessage('');
    setUploadProgress(0);

    try {
      const formData = new FormData();

      if (state.mode === 'preset') {
        formData.append('template_id', String(state.template?.id));
        formData.append('fields', JSON.stringify(state.values));
      } else {
        if (state.customTemplateFile) {
          formData.append('background_image', state.customTemplateFile);
        }
        formData.append('qr_zone', JSON.stringify(state.qrZone));
      }

      if (state.qrZip) {
        formData.append('qr_zip', state.qrZip);
      }

      const endpoint = state.mode === 'preset'
        ? '/generate/preset'
        : '/generate/custom';

      // Pas de Content-Type manuel : le navigateur ajoute le boundary multipart
      const response = await apiClient.post(endpoint, formData, {
        responseType: 'blob',
        onUploadProgress: (progress) => {
          if (progress.total) {
            setUploadProgress(Math.round((progress.loaded / progress.total) * 100));
          }
        },
      });

      const blob = new Blob([response.data], { type: 'application/zip' });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      const stamp = new Date().toISOString().slice(0, 19).replace('T', '_').replace(/:/g, '-');
      link.href = url;
      link.download = `tickets_${stamp}.zip`;
      link.click();
      window.URL.revokeObjectURL(url);

      setSuccessMessage(
        `${state.qrCount ?? ''} visuel(s) généré(s). Le téléchargement a démarré.`.trim(),
      );
    } catch (error) {
      setErrorMessage(await getErrorMessage(error, 'Erreur lors de la génération.'));
    } finally {
      setIsGenerating(false);
      setUploadProgress(null);
    }
  }

  function restart() {
    setState(initialState);
    setStep(1);
    setSuccessMessage('');
    setErrorMessage('');
  }

  async function handleLogout() {
    try {
      await apiClient.post('/logout');
    } catch (error) {
      console.error('Erreur lors de la déconnexion:', error);
    } finally {
      localStorage.removeItem(TOKEN_KEY);
      setIsAuthenticated(false);
      setLoginError('');
      restart();
    }
  }

  function stepTitle() {
    const titles: Record<number, string> = {
      1: 'Choisissez votre support',
      2: 'Quel est votre secteur ?',
      3: 'Comment souhaitez-vous créer ?',
      4: 'Sélectionnez votre template',
      5: 'Personnalisez et générez',
    };

    return titles[step];
  }

  const errorBanner = errorMessage ? (
    <div className="error-message" role="alert">
      <AlertTriangle size={18} aria-hidden="true" />
      <span>{errorMessage}</span>
    </div>
  ) : null;

  if (isCheckingSession) {
    return (
      <main className="auth-page">
        <section className="auth-card">
          <p className="eyebrow">TICKETLAB</p>
          <h1>Chargement…</h1>
        </section>
      </main>
    );
  }

  if (!isAuthenticated) {
    return (
      <main className="auth-page">
        <section className="auth-card">
          <div className="brand-mark" aria-hidden="true">
            <span className="brand-mark__notch" />
            <span>TicketLab</span>
          </div>

          <p className="eyebrow">STUDIO DE GÉNÉRATION</p>
          <h1>Créez vos tickets, flyers et affiches en quelques minutes.</h1>
          <p className="auth-description">
            Personnalisez un modèle, ajoutez vos QR codes et récupérez vos
            visuels prêts à utiliser.
          </p>

          <form className="login-form" onSubmit={login}>
            <label>
              Adresse email
              <input
                type="email"
                value={email}
                placeholder="vous@entreprise.com"
                onChange={(event) => setEmail(event.target.value)}
              />
            </label>

            <label>
              Mot de passe
              <input
                type="password"
                value={password}
                placeholder="••••••••"
                onChange={(event) => setPassword(event.target.value)}
              />
            </label>

            {loginError && <p className="form-error">{loginError}</p>}

            <button
              className="primary-button"
              type="submit"
              disabled={isLoggingIn}
            >
              {isLoggingIn ? 'Connexion…' : 'Se connecter'}
            </button>
          </form>

          <p className="auth-hint">
            Accès réservé. Contactez l'administrateur pour obtenir un compte.
          </p>
        </section>

        <aside className="auth-visual">
          <div className="auth-visual__glow auth-visual__glow--one" />
          <div className="auth-visual__glow auth-visual__glow--two" />
          <div className="auth-ticket">
            <span className="auth-ticket__label">TICKETLAB</span>
            <strong>Votre ticket.<br />Votre style.</strong>
            <span className="auth-ticket__line" />
            <div className="fake-qr">
              {Array.from({ length: 25 }).map((_, index) => (
                <i key={index} className={`fake-qr__cell fake-qr__cell--${index}`} />
              ))}
            </div>
          </div>
        </aside>
      </main>
    );
  }

  return (
    <main className="app-shell">
      <header className="topbar">
        <button className="logo" type="button" onClick={restart}>
          <span className="logo__shape" aria-hidden="true" />
          <span>TicketLab</span>
        </button>

        <div className="topbar__right">
          <span className="topbar__badge">Ticketche ecosystem</span>
          <button
            className="logout-button"
            type="button"
            onClick={handleLogout}
          >
            Déconnexion
          </button>
        </div>
      </header>

      <section className="progress-section">
        <div className="progress-header">
          <div>
            <p className="eyebrow">NOUVEAU PROJET</p>
            <h1>{stepTitle()}</h1>
          </div>
          <span className="step-counter">Étape {step} sur 5</span>
        </div>

        <div className="progress-track">
          {[1, 2, 3, 4, 5].map((item) => (
            <span
              className={`progress-track__item ${
                item <= step ? 'progress-track__item--active' : ''
              }`}
              key={item}
            />
          ))}
        </div>
      </section>

      <section className="workspace">
        {step < 5 && errorBanner}

        {step === 1 && (
          <div className="choice-grid choice-grid--support">
            {supportChoices.map((choice) => (
              <button
                className="choice-card"
                key={choice.value}
                type="button"
                onClick={() => chooseSupport(choice.value)}
              >
                <span className="choice-card__icon">
                  <choice.icon size={28} aria-hidden="true" />
                </span>
                <strong>{choice.title}</strong>
                <span>{choice.description}</span>
                <em>Choisir →</em>
              </button>
            ))}
          </div>
        )}

        {step === 2 && (
          <div className="choice-grid choice-grid--sector">
            {sectorChoices.map((choice) => (
              <button
                className="choice-card choice-card--sector"
                key={choice.value}
                type="button"
                onClick={() => chooseSector(choice.value)}
              >
                <span className="choice-card__icon">
                  <choice.icon size={28} aria-hidden="true" />
                </span>
                <strong>{choice.title}</strong>
                <em>Choisir →</em>
              </button>
            ))}
          </div>
        )}

        {step === 3 && (
          <div className="mode-grid">
            <button
              className="mode-card"
              type="button"
              onClick={() => chooseMode('preset')}
            >
              <span className="mode-card__number">01</span>
              <span className="mode-card__icon">
                <Sparkles size={28} aria-hidden="true" />
              </span>
              <h2>Utiliser un template TicketLab</h2>
              <p>
                Choisissez un modèle existant, remplissez les informations et
                ajoutez vos QR codes.
              </p>
              <span className="mode-card__cta">Explorer les templates →</span>
            </button>

            <button
              className="mode-card mode-card--custom"
              type="button"
              onClick={() => chooseMode('custom')}
            >
              <span className="mode-card__number">02</span>
              <span className="mode-card__icon">
                <Upload size={28} aria-hidden="true" />
              </span>
              <h2>Uploader mon propre template</h2>
              <p>
                Importez votre visuel et choisissez précisément l'emplacement
                du QR code.
              </p>
              <span className="mode-card__cta">Importer un visuel →</span>
            </button>
          </div>
        )}

        {step === 4 && state.mode === 'preset' && (
          <div className="template-section">
            {isLoadingTemplates ? (
              <div className="empty-state">
                <Loader2 className="icon-spin" size={32} aria-hidden="true" />
                <h2>Chargement des templates...</h2>
              </div>
            ) : availableTemplates.length > 0 ? (
              <div className="template-grid">
                {availableTemplates.map((template) => (
                  <button
                    className="template-card"
                    key={template.id}
                    type="button"
                    onClick={() => selectTemplate(template)}
                  >
                    <div className="template-card__preview">
                      <img src={template.imagePath} alt={template.name} />
                    </div>
                    <div className="template-card__footer">
                      <div>
                        <strong>{template.name}</strong>
                        <span>
                          {template.type} · {template.sector}
                        </span>
                      </div>
                      <b>Utiliser</b>
                    </div>
                  </button>
                ))}
              </div>
            ) : (
              <div className="empty-state">
                <Puzzle size={32} aria-hidden="true" />
                <h2>Aucun template disponible pour ce choix</h2>
                <p>
                  Les futurs templates seront ajoutés progressivement. Essaie
                  le Ticket Stand ou le Ticket Lavage, ou utilise ton propre
                  template.
                </p>
                <button
                  className="primary-button"
                  type="button"
                  onClick={() => chooseMode('custom')}
                >
                  Uploader mon template
                </button>
              </div>
            )}
          </div>
        )}

        {step === 4 && state.mode === 'custom' && (
          <div className="custom-upload-panel">
            <div>
              <p className="eyebrow">VOTRE DESIGN</p>
              <h2>Importez le fond de votre {state.support}</h2>
              <p>
                Formats acceptés : JPG et PNG. Une fois le visuel importé,
                vous pourrez définir la position exacte du QR code.
              </p>
            </div>

            <label className="upload-dropzone">
              <input
                accept="image/png,image/jpeg"
                type="file"
                onChange={handleCustomTemplateUpload}
              />
              <span className="upload-dropzone__icon">
                <Upload size={24} aria-hidden="true" />
              </span>
              <strong>Déposez votre image ici</strong>
              <span>ou cliquez pour parcourir vos fichiers</span>
              <small>PNG ou JPG</small>
            </label>
          </div>
        )}

        {step === 5 && selectedTemplateImage && (
          <div className="editor-layout">
            <section className="editor-panel">
              <div className="editor-panel__header">
                <div>
                  <p className="eyebrow">APERÇU EN DIRECT</p>
                  <h2>
                    {state.mode === 'preset'
                      ? state.template?.name
                      : 'Votre template personnalisé'}
                  </h2>
                </div>

                {state.mode === 'custom' && (
                  <p className="settings-help">
                    Glissez la zone pour la déplacer, tirez un coin pour la
                    redimensionner, ou dessinez-en une nouvelle.
                  </p>
                )}
              </div>

              <div className="canvas-scroll">
                <div
                  className="ticket-canvas"
                  style={{
                    aspectRatio: `${canvasWidth} / ${canvasHeight}`,
                  }}
                >
                  <img
                    className="ticket-canvas__image"
                    src={selectedTemplateImage}
                    alt="Aperçu du template"
                  />

                  {fields.map((field) => {
                    const left = `${(field.x / canvasWidth) * 100}%`;
                    const top = `${(field.y / canvasHeight) * 100}%`;
                    const width = field.maxWidth
                      ? `${(field.maxWidth / canvasWidth) * 100}%`
                      : undefined;
                    const text =
                      field.type === 'counter'
                        ? '1'
                        : state.values[field.key] || field.placeholder;

                    return (
                      <span
                        className="ticket-field-preview"
                        key={field.key}
                        style={{
                          left,
                          top,
                          width,
                          color: field.color,
                          // cqw = % de la largeur de l'aperçu : la taille du
                          // texte ne dépend plus de la fenêtre du navigateur
                          fontSize: `${(field.fontSize / canvasWidth) * 100}cqw`,
                          fontWeight: field.fontWeight ?? 500,
                          lineHeight: field.lineHeight ?? 1.4,
                          textAlign: field.align ?? 'left',
                          whiteSpace: field.maxWidth ? 'pre-wrap' : 'pre',
                        }}
                      >
                        {text}
                      </span>
                    );
                  })}

                  {state.mode === 'custom' && state.customImageSize ? (
                    <QrZoneEditor
                      zone={state.qrZone}
                      image={state.customImageSize}
                      onChange={updateQrZone}
                    />
                  ) : (
                    <div
                      className="qr-zone-preview"
                      style={{
                        left: `${(activeQrZone.x / canvasWidth) * 100}%`,
                        top: `${(activeQrZone.y / canvasHeight) * 100}%`,
                        width: `${(activeQrZone.width / canvasWidth) * 100}%`,
                        height: `${(activeQrZone.height / canvasHeight) * 100}%`,
                      }}
                    >
                      <span>QR code</span>
                    </div>
                  )}
                </div>
              </div>
            </section>

            <aside className="settings-panel">
              {state.mode === 'preset' ? (
                <section className="settings-section">
                  <div className="settings-section__title">
                    <PenLine size={18} aria-hidden="true" />
                    <h3>Informations à afficher</h3>
                  </div>

                  {editableFields.map((field) => (
                    <label className="form-control" key={field.key}>
                      <span>
                        {field.label}
                        {field.required && <b> *</b>}
                      </span>

                      {field.type === 'textarea' ? (
                        <textarea
                          value={state.values[field.key] ?? ''}
                          placeholder={field.placeholder}
                          onChange={(event) =>
                            updateField(field, event.target.value)
                          }
                        />
                      ) : (
                        <input
                          value={state.values[field.key] ?? ''}
                          placeholder={field.placeholder}
                          onChange={(event) =>
                            updateField(field, event.target.value)
                          }
                        />
                      )}
                    </label>
                  ))}
                </section>
              ) : (
                <section className="settings-section">
                  <div className="settings-section__title">
                    <Crosshair size={18} aria-hidden="true" />
                    <h3>Position de la zone QR</h3>
                  </div>

                  <p className="settings-help">
                    Ajustez la zone à la souris dans l'aperçu ou saisissez les
                    coordonnées (en pixels de l'image
                    {state.customImageSize
                      ? ` : ${state.customImageSize.width} × ${state.customImageSize.height}`
                      : ''}
                    ).
                  </p>

                  <div className="qr-input-grid">
                    {(['x', 'y', 'width', 'height'] as Array<keyof QrZone>).map(
                      (key) => (
                        <label className="form-control" key={key}>
                          <span>
                            {key === 'x'
                              ? 'Position X'
                              : key === 'y'
                                ? 'Position Y'
                                : key === 'width'
                                  ? 'Largeur'
                                  : 'Hauteur'}
                          </span>
                          <input
                            min="0"
                            type="number"
                            value={state.qrZone[key]}
                            onChange={(event) =>
                              updateQrZoneFromInputs(key, event.target.value)
                            }
                          />
                        </label>
                      ),
                    )}
                  </div>

                  <div className="qr-presets">
                    <span>Tailles rapides :</span>
                    {QR_SIZES.map((size) => (
                      <button
                        key={size}
                        type="button"
                        onClick={() =>
                          state.customImageSize &&
                          updateQrZone(
                            clampZone(
                              { ...state.qrZone, width: size, height: size },
                              state.customImageSize,
                            ),
                          )
                        }
                      >
                        {size} × {size}
                      </button>
                    ))}
                  </div>
                </section>
              )}

              <section className="settings-section">
                <div className="settings-section__title">
                  <QrCode size={18} aria-hidden="true" />
                  <h3>ZIP des QR codes</h3>
                </div>

                <label className="zip-upload">
                  <input
                    accept=".zip,application/zip"
                    type="file"
                    onChange={handleQrZipUpload}
                  />
                  <span className="zip-upload__icon">ZIP</span>
                  <span>
                    <strong>
                      {state.qrZip
                        ? state.qrZip.name
                        : 'Ajouter le ZIP de QR codes'}
                    </strong>
                    <small>
                      {state.qrZip
                        ? `${state.qrCount} QR code(s) détecté(s) · ${state.qrCount} visuel(s) seront générés`
                        : 'Un QR code par ticket généré'}
                    </small>
                  </span>
                </label>
              </section>

              {errorBanner}

              {successMessage && (
                <div className="success-message">{successMessage}</div>
              )}

              <button
                className="primary-button primary-button--generate"
                disabled={!canGenerate() || isGenerating}
                type="button"
                onClick={generateTickets}
              >
                {isGenerating ? 'Génération en cours…' : 'Générer les visuels'}
              </button>

              {isGenerating && (
                <>
                  <div className="generation-progress" aria-hidden="true">
                    <span style={{ width: `${uploadProgress ?? 0}%` }} />
                  </div>
                  <p className="generation-hint">
                    {uploadProgress !== null && uploadProgress < 100
                      ? `Envoi des fichiers… ${uploadProgress} %`
                      : `Génération de ${state.qrCount ?? ''} visuel(s) en cours, cela peut prendre quelques instants…`}
                  </p>
                </>
              )}

              <p className="generation-hint">
                Chaque QR du ZIP produira un ticket, flyer ou affiche distinct.
              </p>
            </aside>
          </div>
        )}
      </section>

      {step > 1 && (
        <footer className="wizard-footer">
          <button
            className="back-button"
            type="button"
            onClick={() => {
              setErrorMessage('');
              setStep((current) => current - 1);
            }}
          >
            <ArrowLeft size={16} aria-hidden="true" /> Retour
          </button>
        </footer>
      )}
    </main>
  );
}

export default App;