import './lib/zodConfig'; // must stay first: configures zod before any schema is built
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router-dom';
import './i18n';
import { applyDocumentDirection } from './i18n';
import { VaultKeyProvider } from './lib/vaultKey';
import { router } from './routes';
import './styles/base.css';

applyDocumentDirection('en');


const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
    },
  },
});

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <VaultKeyProvider>
        <RouterProvider router={router} />
      </VaultKeyProvider>
    </QueryClientProvider>
  </StrictMode>,
);
