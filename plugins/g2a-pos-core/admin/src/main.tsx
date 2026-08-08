import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './styles.css';

const mount = document.getElementById('g2a-pos-dashboard');
if (mount) {
  ReactDOM.createRoot(mount).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
}
