import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { createBrowserRouter, Navigate, Outlet } from 'react-router-dom';
import { ForgotPasswordPage } from '../features/auth/ForgotPasswordPage';
import { LoginPage } from '../features/auth/LoginPage';
import { RegisterPage } from '../features/auth/RegisterPage';
import { ResetPasswordPage } from '../features/auth/ResetPasswordPage';
import { fetchMe } from '../features/auth/authApi';
import { DashboardPage } from '../features/dashboard/DashboardPage';
import { RecoveryPage } from '../features/recovery/RecoveryPage';
import { SecurityPage } from '../features/security/SecurityPage';
import { RecipientItemsPage } from '../features/vault/RecipientItemsPage';
import { RecipientsPage } from '../features/vault/RecipientsPage';
import { TrashPage } from '../features/vault/TrashPage';
import { AppLayout } from './AppLayout';

/**
 * Route guard for the authenticated SPA shell (docs/10_Frontend_Architecture.md §4).
 * Signed-link route trees (check-in confirm, delivery access) arrive in Phase 3 as
 * separate, dependency-light trees.
 */
function RequireAuth() {
  const { t } = useTranslation('common');
  const { isPending, isError } = useQuery({ queryKey: ['me'], queryFn: fetchMe, retry: false });

  if (isPending) {
    return (
      <main className="page">
        <p className="muted">{t('loading')}</p>
      </main>
    );
  }

  if (isError) {
    return <Navigate to="/login" replace />;
  }

  return <Outlet />;
}

export const router = createBrowserRouter([
  { path: '/', element: <Navigate to="/login" replace /> },
  { path: '/register', element: <RegisterPage /> },
  { path: '/login', element: <LoginPage /> },
  { path: '/forgot-password', element: <ForgotPasswordPage /> },
  { path: '/reset-password', element: <ResetPasswordPage /> },
  {
    element: <RequireAuth />,
    children: [
      // Recovery requires a session but not an unlocked vault.
      { path: '/recover', element: <RecoveryPage /> },
      {
        element: <AppLayout />,
        children: [
          { path: '/dashboard', element: <DashboardPage /> },
          { path: '/recipients', element: <RecipientsPage /> },
          { path: '/recipients/:recipientId/messages', element: <RecipientItemsPage /> },
          // The rich-text editor is the largest dependency in the app — loaded only here.
          { path: '/recipients/:recipientId/messages/new', lazy: () => import('../features/vault/ItemEditorPage') },
          { path: '/recipients/:recipientId/messages/:itemId', lazy: () => import('../features/vault/ItemEditorPage') },
          // File collections (file_bundle items) — no rich-text editor, so a separate, light chunk.
          { path: '/recipients/:recipientId/files/new', lazy: () => import('../features/vault/BundlePage') },
          { path: '/recipients/:recipientId/files/:itemId', lazy: () => import('../features/vault/BundlePage') },
          // Preview as recipient (Decision #30) — its own chunk, like the file collections.
          { path: '/recipients/:recipientId/preview', lazy: () => import('../features/vault/preview/PreviewPage') },
          { path: '/trash', element: <TrashPage /> },
          { path: '/security', element: <SecurityPage /> },
        ],
      },
    ],
  },
  { path: '*', element: <Navigate to="/login" replace /> },
]);
