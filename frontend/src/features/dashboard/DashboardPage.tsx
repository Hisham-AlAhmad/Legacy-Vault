import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Card } from '../../components/Card';
import { fetchMe } from '../auth/authApi';
import './DashboardPage.css';

/**
 * Dashboard shell for Phase 1 — the check-in panel is the most prominent element
 * (docs/03_UI_UX_Guidelines.md §7), shown as an honest placeholder until Phase 3 makes it
 * real (DASH-1 note in docs/17_Roadmap.md Phase 2). Security checklist nudges 2FA +
 * Recovery Key (docs/02_User_Flows.md Flow 1 step 7).
 */
export function DashboardPage() {
  const { t } = useTranslation('dashboard');
  const { data: user } = useQuery({ queryKey: ['me'], queryFn: fetchMe });

  return (
    <main className="page page--wide">
      <h1>{t('title')}</h1>

      <div className="dashboard-grid">
        <Card className="dashboard-grid__checkin">
          <h2>{t('checkin.title')}</h2>
          <p className="muted">{t('checkin.placeholder')}</p>
        </Card>

        <Card>
          <h2>{t('empty.title')}</h2>
          <p className="muted">{t('empty.body')}</p>
          <Link to="/recipients">{t('empty.cta')}</Link>
        </Card>

        <Card>
          <h2>{t('securityScore.title')}</h2>
          <ul className="dashboard-checklist">
            <li className="dashboard-checklist__item dashboard-checklist__item--done">
              {t('securityScore.recoveryKeySaved')}
            </li>
            <li
              className={`dashboard-checklist__item${user?.twoFactorEnabled ? ' dashboard-checklist__item--done' : ''}`}
            >
              {t('securityScore.twoFactor')}:{' '}
              {user?.twoFactorEnabled ? (
                t('securityScore.enabled')
              ) : (
                <Link to="/security">{t('securityScore.enableNow')}</Link>
              )}
            </li>
          </ul>
        </Card>
      </div>
    </main>
  );
}
