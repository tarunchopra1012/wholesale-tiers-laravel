import { AppProvider } from '@shopify/polaris';
import enTranslations from '@shopify/polaris/locales/en.json';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import Customers from './pages/Customers.jsx';
import Preview from './pages/Preview.jsx';
import Settings from './pages/Settings.jsx';

export default function App() {
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
