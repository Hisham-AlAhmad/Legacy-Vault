import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { Button } from '../components/Button';
import { useVaultKey } from '../lib/vaultKey';
import { logout } from '../features/auth/authApi';
import './AppLayout.css';

/** Authenticated app shell: header nav, language switch, sign out. */
export function AppLayout() {
  const { t, i18n } = useTranslation('common');
  const { t: tDash } = useTranslation('dashboard');
  const { t: tVault } = useTranslation('vault');
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { clearMasterKey } = useVaultKey();

  const signOut = async () => {
    try {
      await logout();
    } finally {
      clearMasterKey(); // key material never survives the session (AI_RULES.md #2)
      queryClient.clear(); // per-user cache is dropped on logout
      navigate('/login');
    }
  };

  const otherLocale = i18n.language === 'ar' ? 'en' : 'ar';

  return (
    <div className="app-shell">
      <header className="app-shell__header">
        <NavLink to="/dashboard" className="app-shell__brand">
          {t('appName')}
        </NavLink>
        <nav className="app-shell__nav" aria-label={t('appName')}>
          <NavLink to="/dashboard">{tDash('title')}</NavLink>
          <NavLink to="/recipients">{tVault('nav')}</NavLink>
          <NavLink to="/trash">{tVault('trash.nav')}</NavLink>
          <NavLink to="/security">{tDash('security.title')}</NavLink>
        </nav>
        <div className="app-shell__actions">
          <Button variant="ghost" onClick={() => void i18n.changeLanguage(otherLocale)} lang={otherLocale}>
            {t(`language.${otherLocale}`)}
          </Button>
          <Button variant="secondary" onClick={() => void signOut()}>
            {t('actions.signOut')}
          </Button>
        </div>
      </header>
      <Outlet />
    </div>
  );
}
