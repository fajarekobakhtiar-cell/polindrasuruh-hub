css_path = r'c:\laragon\www\polindrasuruh\assets\css\style.css'
with open(css_path, 'r', encoding='utf-8') as f:
    css = f.read()

drivers_unified_css = """
/* --- UNIFIED DRIVERS CARD ON HOME (MENYATU, TIDAK TERPISAH SATU-SATU) --- */
.drivers-unified-card {
    background: rgba(255, 255, 255, 0.97);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.9);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    margin-bottom: 35px;
}

.driver-row-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 1px solid #f1f5f9;
    gap: 14px;
    transition: background 0.15s ease;
}

.driver-row-item:last-child {
    border-bottom: none;
}

.driver-row-item:hover {
    background: #f8fafc;
}

.driver-row-item.offline {
    opacity: 0.72;
}

.driver-row-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 0;
}

.driver-avatar-home {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    font-weight: 800;
    color: #0f172a;
    flex-shrink: 0;
}

.avatar-online {
    background: linear-gradient(135deg, #ffd21f 0%, #f59e0b 100%);
    box-shadow: 0 3px 10px rgba(245, 158, 11, 0.35);
}

.avatar-offline {
    background: #cbd5e1;
    color: #64748b;
}

.driver-row-details h4 {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 3px 0;
}

.driver-row-right {
    flex-shrink: 0;
}

.btn-choose-driver-compact {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 16px;
    border-radius: 10px;
    background: #0f172a;
    color: #ffd21f;
    font-size: 12px;
    font-weight: 800;
    text-decoration: none;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.btn-choose-driver-compact:hover {
    background: #1e293b;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2);
}

.btn-disabled-driver-compact {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 14px;
    border-radius: 10px;
    background: #f1f5f9;
    color: #94a3b8;
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
}
"""

marker = '5.2 LAYANAN (3-COL SERVICE SHOWCASE)'
idx = css.find(marker)
comment_start = css.rfind('/*', 0, idx)

css = css[:comment_start] + drivers_unified_css + '\n' + css[comment_start:]

with open(css_path, 'w', encoding='utf-8') as f:
    f.write(css)

open_b = css.count('{')
close_b = css.count('}')
print(f"Braces balanced: {open_b == close_b} ({open_b} open, {close_b} close)")
