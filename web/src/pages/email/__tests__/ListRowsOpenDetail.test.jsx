import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/emailService', () => ({ emailService: { list: vi.fn(), create: vi.fn() } }));
vi.mock('../../../services/whatsappService', () => ({ whatsappService: { list: vi.fn(), create: vi.fn() } }));
vi.mock('../../../services/landingService', () => ({ landingService: { list: vi.fn(), create: vi.fn() } }));

import { emailService } from '../../../services/emailService';
import { whatsappService } from '../../../services/whatsappService';
import { landingService } from '../../../services/landingService';
import { EmailListPage } from '../EmailListPage';
import { WhatsappListPage } from '../../whatsapp/WhatsappListPage';
import { LandingListPage } from '../../landing/LandingListPage';

/** Email, WhatsApp and landing-page list rows open their detail route. */
function renderAt(path, page) {
  return renderWithAuth(
    <Routes>
      <Route path={path} element={page} />
      <Route path={`${path}/:id`} element={<p>detail page</p>} />
    </Routes>,
    { route: path },
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  emailService.list.mockResolvedValue({ items: [{ id: 7, subject: 'Launch day', status: 'draft' }] });
  whatsappService.list.mockResolvedValue({ items: [{ id: 8, template_name: 'promo', status: 'draft' }] });
  landingService.list.mockResolvedValue({ items: [{ id: 9, title: 'GST guide', slug: 'gst', status: 'draft' }] });
});

describe('channel list rows', () => {
  it('email row opens /email/:id', async () => {
    renderAt('/email', <EmailListPage />);
    fireEvent.click(await screen.findByRole('row', { name: /open email campaign launch day/i }));
    expect(await screen.findByText('detail page')).toBeInTheDocument();
  });

  it('whatsapp row opens /whatsapp/:id', async () => {
    renderAt('/whatsapp', <WhatsappListPage />);
    fireEvent.click(await screen.findByRole('row', { name: /open whatsapp campaign promo/i }));
    expect(await screen.findByText('detail page')).toBeInTheDocument();
  });

  it('landing row opens /landing/:id on Enter', async () => {
    renderAt('/landing', <LandingListPage />);
    const row = await screen.findByRole('row', { name: /open landing page gst guide/i });
    fireEvent.keyDown(row, { key: 'Enter' });
    expect(await screen.findByText('detail page')).toBeInTheDocument();
  });
});
