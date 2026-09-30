import { useMemo, useState, useEffect } from 'react';
import type { ChangeEvent, MouseEvent } from 'react';
import './App.css';
import apiClient from './api/client';
import type {
  CreationMode,
  GeneratorState,
  QrZone,
  Sector,
  SupportType,
  TemplateField,
  TicketTemplate,
} from './types/ticketlab';

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
  qrZone: initialQrZone,
  values: {},
  qrZip: null,
};

const supportChoices: Array<{
  value: SupportType;
  title: string;
  description: string;
  icon: string;
}> = [
  {
    value: 'ticket',
    title: 'Ticket',
    description: "Coupons, accès, bons d'achat et prestations.",
    icon: '🎟️',
  },
  {
    value: 'flyer',
    title: 'Flyer',
    description: 'Supports promotionnels à distribuer.',
    icon: '📄',
  },
  {
    value: 'affiche',
    title: 'Affiche',
    description: 'Visuels grand format pour informer.',
    icon: '🖼️',
  },
];

const sectorChoices: Array<{
  value: Sector;
  title: string;
  icon: string;
}> = [
  { value: 'stand', title: 'Stand', icon: '🏪' },
  { value: 'evenement', title: 'Événement', icon: '🎉' },
  { value: 'parking', title: 'Parking', icon: '🅿️' },
  { value: 'garage', title: 'Garage', icon: '🔧' },
  { value: 'lavage', title: 'Lavage', icon: '🚘' },
];

function App() {
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loginError, setLoginError] = useState('');
  const [step, setStep] = useState(1);
  const [state, setState] = useState<GeneratorState>(initialState);
  const [isGenerating, setIsGenerating] = useState(false);
  const [successMessage, setSuccessMessage] = useState('');
  const [isDraggingQr, setIsDraggingQr] = useState(false);
  const [availableTemplates, setAvailableTemplates] = useState<TicketTemplate[]>([]);
  const [isLoadingTemplates, setIsLoadingTemplates] = useState(false);

  useEffect(() => {
    if (state.support && state.sector) {
      loadTemplates();
    }
  }, [state.support, state.sector]);

  async function loadTemplates() {
    setIsLoadingTemplates(true);
    try {
      const response = await apiClient.get('/templates', {
        params: {
          type: state.support,
          sector: state.sector,
        },
      });

      const templates = response.data.map((t: any) => ({
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
    } finally {
      setIsLoadingTemplates(false);
    }
  }

  const selectedTemplateImage =
    state.mode === 'preset'
      ? state.template?.imagePath ?? null
      : state.customTemplatePreview;

  const canvasWidth =
    state.mode === 'preset'
      ? state.template?.width ?? 1000
      : 1000;

  const canvasHeight =
    state.mode === 'preset'
      ? state.template?.height ?? 650
      : 650;

  const activeQrZone =
    state.mode === 'preset' && state.template
      ? state.template.qrZone
      : state.qrZone;

  const fields =
    state.mode === 'preset' && state.template ? state.template.fields : [];

  function updateState(patch: Partial<GeneratorState>) {
    setState((current) => ({ ...current, ...patch }));
    setSuccessMessage('');
  }

  async function login(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!email.trim() || !password.trim()) {
      setLoginError('Renseigne ton email et ton mot de passe.');
      return;
    }

    try {
      const response = await apiClient.post('/login', {
        email: email.trim(),
        password: password.trim(),
      });

      const { token, user } = response.data;

      localStorage.setItem('ticketlab_token', token);

      setLoginError('');
      setIsAuthenticated(true);
    } catch (error: any) {
      setLoginError(
        error.response?.data?.message || 'Erreur de connexion'
      );
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

    if (!file) {
      return;
    }

    if (!['image/jpeg', 'image/png'].includes(file.type)) {
      window.alert('Utilise uniquement une image PNG ou JPG.');
      return;
    }

    const reader = new FileReader();

    reader.onload = () => {
      updateState({
        customTemplateFile: file,
        customTemplatePreview: String(reader.result),
      });
      setStep(5);
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

  function updateQrZoneFromInputs(key: keyof QrZone, value: string) {
    const parsedValue = Number(value);

    if (!Number.isFinite(parsedValue)) {
      return;
    }

    updateState({
      qrZone: {
        ...state.qrZone,
        [key]: Math.max(0, Math.round(parsedValue)),
      },
    });
  }

  function handleCanvasClick(event: MouseEvent<HTMLDivElement>) {
    if (state.mode !== 'custom' || !isDraggingQr) {
      return;
    }

    const rect = event.currentTarget.getBoundingClientRect();
    const x = ((event.clientX - rect.left) / rect.width) * canvasWidth;
    const y = ((event.clientY - rect.top) / rect.height) * canvasHeight;

    updateState({
      qrZone: {
        ...state.qrZone,
        x: Math.max(0, Math.round(x - state.qrZone.width / 2)),
        y: Math.max(0, Math.round(y - state.qrZone.height / 2)),
      },
    });

    setIsDraggingQr(false);
  }

  function handleQrZipUpload(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];

    if (!file) {
      return;
    }

    if (!file.name.toLowerCase().endsWith('.zip')) {
      window.alert('Sélectionne un fichier ZIP qui contient les QR codes.');
      return;
    }

    updateState({ qrZip: file });
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
      window.alert(
        'Complète les informations obligatoires et ajoute le ZIP des QR codes.',
      );
      return;
    }

    setIsGenerating(true);
    setSuccessMessage('');

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

      const response = await apiClient.post(endpoint, formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
        responseType: 'blob',
      });

      const blob = new Blob([response.data], { type: 'application/zip' });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `tickets_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.zip`;
      link.click();
      window.URL.revokeObjectURL(url);

      setSuccessMessage('Tickets générés avec succès ! Téléchargement en cours...');
    } catch (error: any) {
      console.error('Erreur lors de la génération:', error);
      setSuccessMessage(
        error.response?.data?.message || 'Erreur lors de la génération'
      );
    } finally {
      setIsGenerating(false);
    }
  }

  function restart() {
    setState(initialState);
    setStep(1);
    setSuccessMessage('');
  }

  async function handleLogout() {
    try {
      await apiClient.post('/logout');
    } catch (error) {
      console.error('Erreur lors de la déconnexion:', error);
    } finally {
      localStorage.removeItem('ticketlab_token');
      setIsAuthenticated(false);
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

            <button className="primary-button" type="submit">
              Se connecter
            </button>
          </form>

          <p className="auth-hint">
            Démonstration locale : n'importe quel email et mot de passe
            permettent d'ouvrir le studio.
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
        {step === 1 && (
          <div className="choice-grid choice-grid--support">
            {supportChoices.map((choice) => (
              <button
                className="choice-card"
                key={choice.value}
                type="button"
                onClick={() => chooseSupport(choice.value)}
              >
                <span className="choice-card__icon">{choice.icon}</span>
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
                <span className="choice-card__icon">{choice.icon}</span>
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
              <span className="mode-card__icon">✨</span>
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
              <span className="mode-card__icon">📤</span>
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
                <span>⏳</span>
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
                <span>🧩</span>
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
              <span className="upload-dropzone__icon">⬆</span>
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
                  <button
                    className={`secondary-button ${
                      isDraggingQr ? 'secondary-button--active' : ''
                    }`}
                    type="button"
                    onClick={() => setIsDraggingQr((value) => !value)}
                  >
                    {isDraggingQr
                      ? 'Cliquez sur le visuel'
                      : 'Déplacer la zone QR'}
                  </button>
                )}
              </div>

              <div className="canvas-scroll">
                <div
                  className={`ticket-canvas ${
                    isDraggingQr ? 'ticket-canvas--placing' : ''
                  }`}
                  style={{
                    aspectRatio: `${canvasWidth} / ${canvasHeight}`,
                  }}
                  onClick={handleCanvasClick}
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

                    return (
                      <span
                        className="ticket-field-preview"
                        key={field.key}
                        style={{
                          left,
                          top,
                          width,
                          color: field.color,
                          fontSize: `${(field.fontSize / canvasWidth) * 100}vw`,
                          fontWeight: field.fontWeight ?? 500,
                        }}
                      >
                        {state.values[field.key] || field.placeholder}
                      </span>
                    );
                  })}

                  <div
                    className="qr-zone-preview"
                    style={{
                      left: `${(activeQrZone.x / canvasWidth) * 100}%`,
                      top: `${(activeQrZone.y / canvasHeight) * 100}%`,
                      width: `${(activeQrZone.width / canvasWidth) * 100}%`,
                      height: `${(activeQrZone.height / canvasHeight) * 100}%`,
                    }}
                  >
                    <div className="qr-zone-preview__pattern">
                      QR
                    </div>
                    <span>QR code</span>
                  </div>
                </div>
              </div>
            </section>

            <aside className="settings-panel">
              {state.mode === 'preset' ? (
                <section className="settings-section">
                  <div className="settings-section__title">
                    <span>✏️</span>
                    <h3>Informations à afficher</h3>
                  </div>

                  {fields.map((field) => (
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
                    <span>⌖</span>
                    <h3>Position de la zone QR</h3>
                  </div>

                  <p className="settings-help">
                    Utilisez les coordonnées ou cliquez sur "Déplacer la zone
                    QR", puis cliquez directement dans l'aperçu.
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
                    {[150, 200, 250].map((size) => (
                      <button
                        key={size}
                        type="button"
                        onClick={() =>
                          updateState({
                            qrZone: {
                              ...state.qrZone,
                              width: size,
                              height: size,
                            },
                          })
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
                  <span>▦</span>
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
                        ? 'Fichier prêt pour la génération'
                        : 'Un QR code par ticket généré'}
                    </small>
                  </span>
                </label>
              </section>

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

              <p className="generation-hint">
                Chaque QR du ZIP produira un ticket, flyer ou affiche distinct.
              </p>
            </aside>
          </div>
        )}
      </section>

      {step > 1 && step < 5 && (
        <footer className="wizard-footer">
          <button
            className="back-button"
            type="button"
            onClick={() => setStep((current) => current - 1)}
          >
            ← Retour
          </button>
        </footer>
      )}
    </main>
  );
}

export default App;