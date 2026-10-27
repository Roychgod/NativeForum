#!/usr/bin/env python3
"""Generate <name>.tokens.css for the bundled presets (Obsidian is hand-made: obsidian.tokens.css)."""
import os
d=os.path.dirname(os.path.abspath(__file__))

def block(p, sel=':root, .sf-wrap'):
    v=lambda k: p[k]
    return f"""{sel}{{

  --sf-bg:{v('page')};
  --sf-text:{v('text')};
  --sf-card:var(--sf-bg-panel, var(--sf-surface));
  --sf-text-panel:var(--sf-text);
  --sf-border:{v('line')};
  --sf-muted:{v('muted')};
  --sf-link:{v('link')};
  --sf-link-hover:{v('primary')};
  --sf-accent:var(--sf-primary);
  --sf-primary:{v('primary')};

  --sf-header-bg:{v('bar')};
  --sf-header-color:{v('bar_text')};
  --sf-header-border:{v('line')};
  --sf-sidebar-header-bg:var(--sf-header-bg);

  --sf-input-bg:{v('field')};
  --sf-input-text:{v('text')};

  --sf-side-bg:var(--sf-bg-panel, var(--sf-surface));
  --sf-side-text:{v('text')};
  --sf-side-border:{v('line')};

  --sf-btn-bg:{v('btn')};
  --sf-btn-text:{v('btn_text')};
  --sf-btn-border:{v('btn_border')};
  --sf-btn-outline-text:{v('text')};
  --sf-btn-primary-bg:var(--sf-primary);
  --sf-btn-primary-text:{v('on_primary')};
  --sf-btn-primary-hover-bg:{v('primary_hover')};
  --sf-btn-secondary-bg:{v('btn')};
  --sf-btn-secondary-text:{v('btn_text')};
  --sf-btn-secondary-hover-bg:{v('btn_hover')};

  --sf-tag-bg:{v('primary_soft')};
  --sf-tag-text:{v('primary_soft_text')};
  --sf-tag-hover-bg:var(--sf-primary);
  --sf-tag-hover-text:{v('on_primary')};

  --sf-pinned-row-bg:{v('pinned_bg')};
  --sf-pinned-row-text:{v('text')};

  --sf-surface:{v('panel')};
  --sf-surface-2:{v('panel2')};
  --sf-radius:{v('radius')};
  --sf-card-header-padding:12px 16px;
  --sf-header-pad:12px 16px;
  --sf-card-header-font-size:1.05em;
  --sf-sidebar-header-font-size:1.05em;
  --sf-sidebar-header-padding:12px 16px;
  --sf-btn-pad-y:8px;
  --sf-btn-pad-x:16px;
  --sf-btn-radius:{v('btn_radius')};
  --sf-focus:{v('focus')};
  --sf-hover:{v('hover')};
  --sf-border-strong:{v('line_strong')};
  --sf-border-strong-2:{v('line_strong2')};
  --sf-ring:0 0 0 3px {v('focus')};
  --sf-shadow:{v('shadow')};
  --sf-userinfo-bg:{v('userinfo')};
  --sf-badge-pinned-bg:{v('yellow')};
  --sf-badge-pinned-color:{v('on_yellow')};
  --sf-pm-box-bg:{v('primary_soft')};
  --sf-pm-box-hover-bg:{v('primary_soft2')};

  /* skin tokens */
  --sk-page-bg:{v('page')};
  --sk-wrap-bg:{v('page')};
  --sk-wrap-pad:20px 0;
  --sk-wrap-radius:{v('radius')};
  --sk-table-head:{v('panel2')};
  --sk-scheme:{v('scheme')};
  --sk-heading:{v('heading')};
  --sk-on-header:{v('bar_text')};
  --sk-on-primary:{v('on_primary')};
  --sk-side-link:{v('heading')};
  --sk-content-link:{v('link')};
  --sk-pagehead-bg:var(--sf-surface);
  --sk-page-item-bg:var(--sf-surface);
  --sk-deep:{v('deep')};
  --sk-field-bg:{v('field')};
  --sk-field-text:{v('text')};
  --sk-menu-bg:{v('panel')};
  --sk-editor-bg:{v('editor')};
  --sk-editor-text:{v('text')};
  --sk-editor-link:{v('link')};
  --sk-editor-bar:{v('editor_bar')};
  --sk-cke-bar:{v('cke_bar')};
  --sk-upload-bg:{v('deep')};
  --sk-avatar-bg:{v('avatar')};
  --sk-avatar-text:{v('avatar_text')};
  --sk-yellow:{v('yellow')};
  --sk-on-yellow:{v('on_yellow')};
  --sk-danger:{v('danger')};
  --sk-primary-soft:{v('primary_soft')};
  --sk-primary-soft-text:{v('primary_soft_text')};
  --sk-hover-soft:{v('hover')};
  --sk-outline-hover:{v('hover')};
  --sk-border-strong:{v('line_strong')};
  --sk-border-strong-2:{v('line_strong2')};
  --sk-ok-bg:{v('ok_bg')};
  --sk-ok-border:{v('ok_border')};
  --sk-ok-text:{v('ok_text')};
  --sk-err-bg:{v('err_bg')};
  --sk-err-border:{v('err_border')};
  --sk-err-text:{v('err_text')};
  --sk-toast-bg:{v('toast_bg')};
  --sk-toast-text:{v('toast_text')};
  --sk-radius:{v('radius')};
  --sk-radius-sm:{v('radius_sm')};
  --sk-icon-radius:{v('icon_radius')};
  --sk-input-radius:{v('input_radius')};
  --sk-badge-radius:{v('badge_radius')};
  --sk-badge-transform:{v('badge_transform')};
  --sk-badge-spacing:{v('badge_spacing')};
  --sk-head-transform:{v('head_transform')};
  --sk-head-spacing:{v('head_spacing')};
  --sk-head-weight:{v('head_weight')};
  --sk-head-size:{v('head_size')};
  --sk-title-weight:{v('title_weight')};
  --sk-btn-weight:{v('btn_weight')};
  --sk-card-shadow:{v('card_shadow')};
  --sk-post-border:{v('post_border')};
  --sk-btn-border:{v('btn_border')};
  --sk-btn-border-hover:{v('btn_border_hover')};
  --sk-toggle-bg:{v('toggle_bg')};
  --sk-toggle-text:{v('bar_text')};
  --sk-toggle-border:transparent;
  --sk-activity-head-margin:-14px -16px 10px;
  --sk-activity-head-radius:{v('radius')} {v('radius')} 0 0;
  --sk-danger-outline-text:{v('danger')};
  --sk-danger-outline-border:{v('danger')};
  --sk-danger-outline-hover-bg:{v('danger')};
  --sk-danger-outline-hover-text:#ffffff;
}}
"""

LIGHT_SHAPE=dict(radius='10px', radius_sm='6px', icon_radius='10px', input_radius='8px', badge_radius='999px', btn_radius='8px',
  badge_transform='none', badge_spacing='0', head_transform='none', head_spacing='0', head_weight='600', head_size='1.02em',
  title_weight='700', btn_weight='600')
LIGHT_ALERTS=dict(ok_bg='#ecfdf3', ok_border='#abefc6', ok_text='#067647', err_bg='#fef3f2', err_border='#fecdca', err_text='#b42318',
  toast_bg='#111827', toast_text='#ffffff', yellow='#fde68a', on_yellow='#713f12')
DARK_ALERTS=dict(ok_bg='rgba(34,197,94,.14)', ok_border='rgba(34,197,94,.4)', ok_text='#bbf7d0', err_bg='rgba(248,113,113,.14)',
  err_border='rgba(248,113,113,.45)', err_text='#fecaca', toast_bg='#f8fafc', toast_text='#0f172a', yellow='#fbbf24', on_yellow='#1f1500')

LIGHT=dict(scheme='light', page='#f3f5f8', text='#1f2937', heading='#111827', muted='#6b7280', link='#1d4ed8',
  primary='#2563eb', primary_hover='#1d4ed8', on_primary='#ffffff', primary_soft='rgba(37,99,235,.10)', primary_soft2='rgba(37,99,235,.16)', primary_soft_text='#1e40af',
  panel='#ffffff', panel2='#f8fafc', bar='#f1f5f9', bar_text='#0f172a', line='#e5e7eb', line_strong='#e2e8f0', line_strong2='#cbd5e1',
  field='#ffffff', deep='#f8fafc', editor='#ffffff', cke_bar='#f1f5f9', editor_bar='#f1f5f9', avatar='#e0e7ff', avatar_text='#3730a3', userinfo='#f8fafc',
  btn='#f1f5f9', btn_hover='#e2e8f0', btn_text='#1f2937', danger='#dc2626', focus='rgba(37,99,235,.25)', hover='rgba(15,23,42,.04)',
  pinned_bg='#fffbeb', shadow='0 1px 2px rgba(16,24,40,.06)', card_shadow='0 1px 2px rgba(16,24,40,.05), 0 1px 3px rgba(16,24,40,.06)',
  post_border='1px solid #e5e7eb', btn_border='#d5dce6', btn_border_hover='#c3ccd8', toggle_bg='rgba(15,23,42,.06)', **LIGHT_SHAPE, **LIGHT_ALERTS)

DARK=dict(scheme='dark', page='#0d1117', text='#e6edf3', heading='#f0f6fc', muted='#8b949e', link='#79b8ff',
  primary='#4c8dff', primary_hover='#6ea2ff', on_primary='#ffffff', primary_soft='rgba(76,141,255,.16)', primary_soft2='rgba(76,141,255,.24)', primary_soft_text='#b6d1ff',
  panel='#161b22', panel2='#1c2129', bar='#21262d', bar_text='#f0f6fc', line='rgba(240,246,252,.10)', line_strong='rgba(240,246,252,.14)',
  line_strong2='rgba(240,246,252,.24)', field='#0d1117', deep='#0d1117', editor='#0d1117', cke_bar='#e6e9ed', editor_bar='#21262d', avatar='#2d333b', avatar_text='#e6edf3',
  userinfo='#1c2129', btn='#2d333b', btn_hover='#373e47', btn_text='#e6edf3', danger='#da3633', focus='rgba(76,141,255,.35)', hover='rgba(240,246,252,.05)',
  pinned_bg='rgba(251,191,36,.08)', shadow='0 1px 0 rgba(0,0,0,.3)', card_shadow='0 1px 0 rgba(0,0,0,.25)',
  post_border='1px solid rgba(240,246,252,.10)', btn_border='rgba(240,246,252,.14)', btn_border_hover='rgba(240,246,252,.22)', toggle_bg='rgba(240,246,252,.08)', **LIGHT_SHAPE, **DARK_ALERTS)

MONO=dict(LIGHT, scheme='light', page='#f5f5f5', text='#1a1a1a', heading='#000000', muted='#6b6b6b', link='#000000',
  primary='#111111', primary_hover='#333333', primary_soft='rgba(0,0,0,.06)', primary_soft2='rgba(0,0,0,.1)', primary_soft_text='#111111',
  bar='#111111', bar_text='#ffffff', line='#e2e2e2', line_strong='#dcdcdc', line_strong2='#bdbdbd', cke_bar='#f2f2f2', editor_bar='#f2f2f2',
  avatar='#111111', avatar_text='#ffffff', userinfo='#fafafa', btn='#f0f0f0', btn_hover='#e4e4e4', btn_text='#111111', danger='#b91c1c',
  focus='rgba(0,0,0,.18)', hover='rgba(0,0,0,.035)', pinned_bg='#fafafa', post_border='1px solid #e2e2e2', btn_border='#d4d4d4', btn_border_hover='#bdbdbd', toggle_bg='rgba(255,255,255,.16)',
  radius='4px', radius_sm='3px', icon_radius='4px', input_radius='4px', badge_radius='3px', btn_radius='4px',
  badge_transform='uppercase', badge_spacing='.05em', head_transform='uppercase', head_spacing='.06em', head_size='.88em',
  head_weight='700', yellow='#111111', on_yellow='#ffffff', card_shadow='none')

CUSTOM=dict(LIGHT, page='#f2f6f5', text='#1d2b2a', heading='#0b1f1d', muted='#5f716f', link='#0f766e',
  primary='#0f766e', primary_hover='#115e59', primary_soft='rgba(15,118,110,.10)', primary_soft2='rgba(15,118,110,.16)', primary_soft_text='#115e59',
  bar='#0f766e', bar_text='#ffffff', line='#dfe7e6', line_strong='#dbe4e3', line_strong2='#c3d1cf', cke_bar='#eef4f3', editor_bar='#eef4f3',
  avatar='#ccfbf1', avatar_text='#115e59', userinfo='#f6faf9', btn='#eef4f3', btn_hover='#e0ebe9', btn_text='#1d2b2a',
  focus='rgba(15,118,110,.25)', pinned_bg='#fffbeb', post_border='1px solid #dfe7e6', btn_border='#cfdcda', btn_border_hover='#b9cac7', toggle_bg='rgba(255,255,255,.18)', radius='12px', input_radius='8px', btn_radius='8px')

HEAD={
 'light':"/* NativeForum preset: Light — white cards on a soft grey page, blue accent, rounded corners. */\n",
 'dark':"/* NativeForum preset: Dark — GitHub-like dark surfaces, blue accent, rounded corners. */\n",
 'mono':"/* NativeForum preset: Monochrome — black & white, sharp, black header bars. */\n",
 'custom':"/* NativeForum preset: Custom — neutral teal starting palette; every Design Studio value you set overrides it. */\n",
 'auto':"/* NativeForum preset: Auto — Light, or Dark when the visitor's system prefers dark (prefers-color-scheme). */\n",
}
SCHEME=lambda s: ".sf-wrap{ color-scheme:%s; }\n" % s
for name,p in [('light',LIGHT),('dark',DARK),('mono',MONO),('custom',CUSTOM)]:
    open(os.path.join(d,name+'.tokens.css'),'w').write(HEAD[name]+block(p)+SCHEME(p['scheme']))
auto=HEAD['auto']+block(LIGHT)+SCHEME('light')+"@media (prefers-color-scheme: dark){\n"+(block(DARK)+SCHEME('dark')).replace('\n','\n  ').rstrip()+"\n}\n"
open(os.path.join(d,'auto.tokens.css'),'w').write(auto)
print('ok')
