import '@shopify/polaris/build/esm/styles.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';

// Named main.tsx, not app.tsx: macOS treats app.tsx and App.tsx as the same
// file, so the two can't sit side by side.
createRoot(document.getElementById('root') as HTMLElement).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
