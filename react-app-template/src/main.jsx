import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
// Orden: base de componentes → estilos de vista (pueden ajustar la base) → estructura.
import './components.css';
import './styles.css';
import './home.css';
import './trips.css';
import './payments.css';
import './account.css';
import './proposals.css';
import './shell.css';

const el = document.getElementById('casanova-portal-root');
if (el) createRoot(el).render(<App />);
