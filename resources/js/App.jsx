import { AppProvider, Banner, Page } from '@shopify/polaris';
import enTranslations from '@shopify/polaris/locales/en.json';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import Customers from './pages/Customers.jsx';
import Preview from './pages/Preview.jsx';
import Settings from './pages/Settings.jsx';

// The admin loads the app in a frame. Opened on its own there is no admin to
// issue ID tokens, so every /api call would answer 401 — say why instead.
const embedded = window.self !== window.top;

export default function App() {
    if (!embedded) {
        return (
            <AppProvider i18n={enTranslations}>
                <Page title="Wholesale Tiers">
                    <Banner tone="warning" title="Open this app from your Shopify admin">
                        <p>
                            It only works inside the admin, which signs each request.
                            In your store's admin, go to Apps and choose wholesale-tiers.
                        </p>
                    </Banner>
                </Page>
            </AppProvider>
        );
    }

    return (
        <AppProvider i18n={enTranslations}>
            <BrowserRouter>
                <Routes>
                    <Route path="/" element={<Customers />} />
                    <Route path="/settings" element={<Settings />} />
                    <Route path="/preview" element={<Preview />} />
                    {/* Laravel serves this page on any path, so an unknown
                        one would otherwise render nothing at all. */}
                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </BrowserRouter>
        </AppProvider>
    );
}
