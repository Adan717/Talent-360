import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import './index.css'
import App from './App.tsx'
import { AppDialogProvider } from './components/ui/AppDialogProvider.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <AppDialogProvider>
        <App />
      </AppDialogProvider>
    </BrowserRouter>
  </StrictMode>,
)
