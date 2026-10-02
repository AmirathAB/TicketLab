import axios, { type AxiosError } from 'axios';

// URL de l'API Laravel : définie dans .env (VITE_API_URL), voir .env.example
const API_BASE_URL: string =
  import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8001/api';

export const TOKEN_KEY = 'ticketlab_token';

/** Événement émis quand l'API répond 401 sur une route protégée. */
export const UNAUTHORIZED_EVENT = 'ticketlab:unauthorized';

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    Accept: 'application/json',
  },
});

// Ajoute le token d'authentification
apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY);

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  return config;
});

// Session expirée : on nettoie et on prévient l'application. On ne recharge
// JAMAIS la page (sinon le message d'erreur du login ne s'affiche pas), et un
// 401 sur /login signifie simplement "identifiants invalides".
apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    const isLoginCall = error.config?.url === '/login';

    if (error.response?.status === 401 && !isLoginCall) {
      localStorage.removeItem(TOKEN_KEY);
      window.dispatchEvent(new Event(UNAUTHORIZED_EVENT));
    }

    return Promise.reject(error);
  },
);

/** Vrai si le backend répond (même sans en-têtes CORS : réponse "opaque"). */
async function isServerReachable(origin: string): Promise<boolean> {
  try {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 3000);
    await fetch(`${origin}/up`, { mode: 'no-cors', signal: controller.signal });
    window.clearTimeout(timer);
    return true;
  } catch {
    return false;
  }
}

/**
 * Extrait un message lisible d'une erreur Axios. Gère le cas des réponses
 * `responseType: 'blob'` où le JSON d'erreur arrive sous forme de Blob.
 */
export async function getErrorMessage(
  error: unknown,
  fallback = 'Une erreur est survenue.',
): Promise<string> {
  const axiosError = error as AxiosError<unknown>;

  if (!axiosError?.isAxiosError) {
    return fallback;
  }

  // Pas de réponse HTTP du tout. Deux causes très différentes se confondent ici :
  // le serveur est vraiment injoignable, OU l'upload a été tronqué par PHP avant
  // d'atteindre Laravel (post_max_size / upload_max_filesize). Dans le second
  // cas, le message « vérifiez votre connexion » envoie l'utilisateur dans la
  // mauvaise direction alors que son fichier est la cause : on distingue les deux.
  if (!axiosError.response) {
    const aborted = axiosError.code === 'ERR_CANCELED';

    if (aborted) {
      return 'Génération annulée.';
    }

    // Le navigateur renvoie le même code (ERR_NETWORK) dans les deux cas : on
    // sonde donc /up (route de santé de Laravel) pour savoir lequel c'est.
    const origin = API_BASE_URL.replace(/\/api\/?$/, '');

    if (await isServerReachable(origin)) {
      return "Le serveur répond, mais la génération a été interrompue avant de renvoyer le fichier. Vérifiez les logs du backend et démarrez-le avec « ./serve.sh 8001 » (limites d'upload et de mémoire configurées), puis réessayez.";
    }

    return `Impossible de joindre le serveur (${origin}). Démarrez le backend avec « ./serve.sh 8001 » et vérifiez que VITE_API_URL dans front/.env pointe vers ce port.`;
  }

  let data: unknown = axiosError.response.data;

  if (data instanceof Blob) {
    try {
      data = JSON.parse(await data.text());
    } catch {
      data = null;
    }
  }

  const payload = data as { message?: string } | null;

  if (payload?.message) {
    return payload.message;
  }

  if (axiosError.response.status === 413) {
    return 'Les fichiers envoyés sont trop volumineux pour le serveur.';
  }

  return fallback;
}

export default apiClient;
