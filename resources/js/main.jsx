import '@shopify/polaris/build/esm/styles.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';

// Named main.jsx, not app.jsx: macOS treats app.jsx and App.jsx as the same
// file, so the two can't sit side by side.
createRoot(document.getElementById('root')).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
