import type { Config } from 'tailwindcss';

export default {
  content: [
    './index.html',
    './src/**/*.{js,ts,jsx,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        ticketlab: {
          yellow: '#FFB400',
          tealDark: '#015F69',
          tealMedium: '#037580',
          tealLight: '#087D86',
          greenTeal: '#226E61',
          greenTealAlt: '#267060',
          white: '#FFFFFF',
          qrBlack: '#000000',
          textOnYellow: '#156660',
          success: '#10B981',
          error: '#EF4444',
          warning: '#FFB400',
          border: 'rgba(255,255,255,0.18)',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
        mono: ['JetBrains Mono', 'monospace'],
      },
    },
  },
  plugins: [],
} satisfies Config;