/* =====================================================
   SIAP-MBG — app.js
   Sistem Informasi Administrasi Program MBG
   ===================================================== */

const State = {
  jenjang: "SMK",
  siswa: 36,
  ompreng: 36,
  kondisi: "baik",
  fotoDataURL: null,
  fotoRetDataURL: null,
  pengambilan: [],
  pengembalian: [],
  notifications: [],
};

let chartMbgMinggu = null;
let camStream = null;
let camMode = null;

const FOTO_MAX_W = 900;
const FOTO_QUALITY = 0.75;

const fetchCred = { credentials: "same-origin" };

/** Tanggal lokal YYYY-MM-DD (hindari geser zona ke UTC) */
function localISODate(d = new Date()) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

async function parseApiJson(resp) {
  const text = await resp.text();
  try {
    return text ? JSON.parse(text) : {};
  } catch {
    console.warn("API non-JSON:", text.slice(0, 160));
    return {
      status: "error",
      pesan: "Respons server tidak valid (bukan JSON)",
      data: [],
    };
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  if (!document.getElementById("shell")) {
    initAuthPortal();
    return;
  }

  initThemeFromStorage();

  setFooterDate();
  initSessionUser();

  initJenjangSMK();

  const today = localISODate();
  const dateInput = document.getElementById("filter-tanggal");
  if (dateInput) dateInput.value = today;

  const weekInput = document.getElementById("filter-minggu");
  if (weekInput) weekInput.value = formatWeekInputValue(new Date());

  await loadData();
  updateNotifPanelContent();

  initSidebarHover();

  document.addEventListener("click", (e) => {
    const fotoBtn = e.target.closest(".rekap-foto-btn");
    if (fotoBtn?.dataset.foto) {
      e.preventDefault();
      openLightbox(fotoBtn.dataset.foto);
      return;
    }

    const slot = document.querySelector(".topbar-notif-slot");
    const panel = document.getElementById("notif-dropdown");
    const btn = document.getElementById("notif-toggle");
    if (!panel?.classList.contains("open") || !slot) return;
    if (slot.contains(e.target)) return;
    panel.classList.remove("open");
    btn?.setAttribute("aria-expanded", "false");
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeLightbox();
  });

  const wrap = document.getElementById("content-wrap");
  const topbar = document.getElementById("topbar");
  if (wrap && topbar) {
    wrap.addEventListener("scroll", () => {
      topbar.classList.toggle("scrolled", wrap.scrollTop > 8);
    });
  }
});

function initThemeFromStorage() {
  const saved = localStorage.getItem("siap-theme");
  const prefersDark =
    window.matchMedia &&
    window.matchMedia("(prefers-color-scheme: dark)").matches;
  const dark = saved === "dark" || (!saved && prefersDark);
  document.documentElement.setAttribute("data-theme", dark ? "dark" : "light");
  syncThemeToggleLabel();
}

function toggleTheme() {
  const cur =
    document.documentElement.getAttribute("data-theme") === "dark"
      ? "dark"
      : "light";
  const next = cur === "dark" ? "light" : "dark";
  document.documentElement.setAttribute("data-theme", next);
  localStorage.setItem("siap-theme", next);
  syncThemeToggleLabel();
  loadCharts();
}

function syncThemeToggleLabel() {
  const dark = document.documentElement.getAttribute("data-theme") === "dark";
  const btn = document.getElementById("theme-toggle");
  if (!btn) return;
  const title = dark
    ? "Mode gelap — klik untuk terang"
    : "Mode terang — klik untuk gelap";
  btn.title = title;
  btn.setAttribute("aria-label", title);
}

function isSidebarMobile() {
  return window.innerWidth <= 768;
}

function initSidebarHover() {
  const sb = document.getElementById("sidebar");
  const edge = document.getElementById("sb-edge-trigger");
  const overlay = document.getElementById("overlay");
  if (!sb || !edge) return;

  let collapseTimer = null;

  const expandSidebar = () => {
    clearTimeout(collapseTimer);
    if (isSidebarMobile()) {
      sb.classList.add("open");
      overlay?.classList.add("show");
    } else {
      sb.classList.remove("collapsed");
    }
  };

  const collapseSidebar = () => {
    if (isSidebarMobile()) {
      sb.classList.remove("open");
      overlay?.classList.remove("show");
    } else {
      sb.classList.add("collapsed");
    }
  };

  const scheduleCollapse = () => {
    clearTimeout(collapseTimer);
    collapseTimer = setTimeout(() => {
      if (!sb.matches(":hover") && !edge.matches(":hover")) {
        collapseSidebar();
      }
    }, 380);
  };

  edge.addEventListener("mouseenter", expandSidebar);
  sb.addEventListener("mouseenter", expandSidebar);
  edge.addEventListener("mouseleave", scheduleCollapse);
  sb.addEventListener("mouseleave", scheduleCollapse);

  const syncSidebarLayout = () => {
    clearTimeout(collapseTimer);
    if (isSidebarMobile()) {
      sb.classList.remove("collapsed");
    } else {
      sb.classList.remove("open");
      overlay?.classList.remove("show");
      if (!sb.matches(":hover") && !edge.matches(":hover")) {
        sb.classList.add("collapsed");
      }
    }
  };

  syncSidebarLayout();
  window.addEventListener("resize", syncSidebarLayout);
}

function initSessionUser() {
  const u = window.SIAP_USER;
  if (!u) return;
  const nameEl = document.getElementById("sb-user-name");
  const iniEl = document.getElementById("sb-user-initial");
  const roleEl = document.getElementById("sb-user-role");
  const labels = {
    perwakilan_kelas: "Perwakilan kelas",
    petugas_mbg: "Petugas MBG",
    dapur_sppg: "Dapur / SPPG",
  };
  if (nameEl) nameEl.textContent = u.nama || "Pengguna";
  if (iniEl) iniEl.textContent = (u.nama || "?").trim().charAt(0).toUpperCase();
  const rl = labels[u.role] || u.role;
  if (roleEl) roleEl.textContent = rl;
  updateSaranAccess();
}

function updateSaranAccess() {
  const allowed = ["perwakilan_kelas", "petugas_mbg"];
  const role = window.SIAP_USER?.role || "";
  const formPanel = document.querySelector(".saran-form-panel");
  if (!formPanel) return;
  const noteEl = document.getElementById("saran-access-note");
  const submitBtn = document.querySelector(
    '.saran-form-panel button[onclick="kirimSaran()"]',
  );
  const jenisField = document.getElementById("saran-jenis");
  const isiField = document.getElementById("saran-isi");

  if (allowed.includes(role)) {
    if (noteEl) {
      noteEl.textContent =
        "Perwakilan kelas dan petugas MBG dapat mengirim masukan. Dapur SPPG menerima masukan ini.";
    }
    if (submitBtn) submitBtn.disabled = false;
    if (jenisField) jenisField.disabled = false;
    if (isiField) isiField.disabled = false;
    formPanel.style.display = "block";
    formPanel.style.opacity = "1";
  } else {
    formPanel.style.display = "none";
  }
}

async function loadNotifications() {
  try {
    const res = await fetch("api/notifikasi.php", fetchCred);
    const j = await parseApiJson(res);
    if (j.status === "ok" && Array.isArray(j.data)) {
      State.notifications = j.data;
    } else {
      State.notifications = [];
    }
  } catch (_) {
    State.notifications = [];
  }
  updateNotifPanelContent();
}

async function markAllNotificationsRead() {
  try {
    await fetch("api/notifikasi.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ action: "mark_all_read" }),
    });
  } catch (_) {}
  await loadNotifications();
}

function updateNotifPanelContent() {
  const body = document.getElementById("notif-dropdown-body");
  if (!body) return;
  const notifications = State.notifications || [];
  const unreadCount = notifications.filter((n) => !n.is_read).length;
  const pending = State.pengambilan.filter(
    (r) => !r.statusKembali && Number(r.jumlah_ambil || r.jumlah || 0) > 0,
  );
  const items = [];

  notifications.forEach((n) => {
    const label =
      n.jenis === "masukan_baru" ? "Masukan baru" : "Feedback masukan";
    const time = n.created_at
      ? new Date(n.created_at).toLocaleString("id-ID")
      : "";
    const deleteBtn =
      n.jenis === "masukan_baru" || n.jenis === "masukan_feedback"
        ? `<button class="notif-item-delete" onclick="deleteNotification(${Number(n.id)})" title="Hapus notifikasi" aria-label="Hapus notifikasi"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-9l-1 1H5v2h14V4z"/></svg></button>`
        : "";
    items.push(
      `<li><div class="notif-item-wrapper"><div><strong>${escapeHtml(label)}</strong> — ${escapeHtml(n.isi)}<br><small>${escapeHtml(time)}</small></div>${deleteBtn}</div></li>`,
    );
  });

  pending.forEach((r) => {
    items.push(
      `<li><strong>${escapeHtml(r.kelas)}</strong> — ompreng belum dikembalikan (${Number(
        r.jumlah_ambil || r.jumlah || 0,
      )} porsi)</li>`,
    );
  });

  if (!items.length) {
    body.innerHTML =
      '<p class="notif-empty">Belum ada notifikasi. Notifikasi akan muncul ketika ada masukan atau tanggapan.</p>';
  } else {
    body.innerHTML = `<ul class="notif-list">${items.join("")}</ul>`;
  }

  safeSet("topbar-notif", unreadCount + pending.length);
}

async function renderMasukanPage() {
  const tb = document.querySelector("#tbl-masukan tbody");
  if (!tb) return;
  const role = window.SIAP_USER?.role || "";
  if (role !== "dapur_sppg") {
    tb.innerHTML =
      '<tr><td colspan="7" class="empty-td">Daftar masukan hanya dapat dilihat oleh Dapur SPPG. Anda tetap dapat mengirim masukan melalui form di atas.</td></tr>';
    return;
  }

  tb.innerHTML =
    '<tr><td colspan="7" class="empty-td">Memuat masukan...</td></tr>';
  try {
    const res = await fetch("api/saran.php", fetchCred);
    const j = await parseApiJson(res);
    if (j.status !== "ok" || !Array.isArray(j.data)) {
      tb.innerHTML = `<tr><td colspan="7" class="empty-td">${escapeHtml(j.pesan || "Gagal memuat daftar masukan")}</td></tr>`;
      return;
    }

    const rows = j.data;
    if (!rows.length) {
      tb.innerHTML =
        '<tr><td colspan="7" class="empty-td">Belum ada masukan</td></tr>';
      return;
    }

    tb.innerHTML = rows
      .map((r) => {
        const waktu = r.created_at
          ? new Date(r.created_at).toLocaleString("id-ID")
          : "—";
        const pengirim = `${escapeHtml(r.nama_pengguna || "")}${r.kelas_info ? ` (${escapeHtml(r.kelas_info)})` : ""}`;
        const jenisLabel =
          r.jenis === "kritik"
            ? "Kritik"
            : r.jenis === "lain"
              ? "Lainnya"
              : "Saran";
        const statusClass =
          r.status === "approved"
            ? "badge badge-green"
            : r.status === "rejected"
              ? "badge badge-red"
              : r.status === "diproses"
                ? "badge badge-orange"
                : "badge badge-blue";
        const statusLabel =
          r.status === "approved"
            ? "Disetujui"
            : r.status === "rejected"
              ? "Ditolak"
              : r.status === "diproses"
                ? "Sedang diproses"
                : "Baru";
        const response = r.respon_dapur ? escapeHtml(r.respon_dapur) : "—";
        const actionButtons =
          window.SIAP_USER?.role === "dapur_sppg" && r.status === "baru"
            ? `<button type="button" class="btn-filter" onclick="requestMasukanFeedback(${Number(
                r.id,
              )}, 'approved')">Setujui</button>
               <button type="button" class="btn-filter" onclick="requestMasukanFeedback(${Number(
                 r.id,
               )}, 'rejected')">Tolak</button>`
            : "—";

        return `<tr>
          <td>${escapeHtml(waktu)}</td>
          <td>${pengirim}</td>
          <td>${escapeHtml(jenisLabel)}</td>
          <td><span class="badge ${statusClass}">${escapeHtml(statusLabel)}</span></td>
          <td class="td-wrap">${escapeHtml(r.isi)}</td>
          <td class="td-wrap">${response}</td>
          <td>${actionButtons}</td>
        </tr>`;
      })
      .join("");
  } catch (err) {
    console.error(err);
    tb.innerHTML =
      '<tr><td colspan="7" class="empty-td">Gagal memuat daftar masukan</td></tr>';
  }
}

async function requestMasukanFeedback(id, status) {
  const alasan = window.prompt(
    `Tambahkan catatan / alasan untuk status ${status === "approved" ? "disetujui" : "ditolak"} (opsional):`,
  );
  try {
    const res = await fetch("api/saran.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({
        action: "feedback",
        id,
        status,
        respon: alasan || "",
      }),
    });
    const j = await parseApiJson(res);
    showToast(j.pesan || (j.status === "ok" ? "Tersimpan" : "Gagal"));
    if (j.status === "ok") {
      await renderMasukanPage();
      await loadNotifications();
    }
  } catch (err) {
    console.error(err);
    showToast("Gagal menyimpan tanggapan");
  }
}

async function clearMasukanHistory() {
  if (!confirm("Hapus semua riwayat masukan? Tindakan ini tidak dapat dibatalkan.")) return;
  try {
    const res = await fetch("api/saran.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ action: "clear_history" }),
    });
    const j = await parseApiJson(res);
    showToast(j.pesan || (j.status === "ok" ? "Riwayat dihapus" : "Gagal"));
    if (j.status === "ok") {
      await renderMasukanPage();
    }
  } catch (err) {
    console.error(err);
    showToast("Gagal menghapus riwayat");
  }
}

function escapeHtml(s) {
  const d = document.createElement("div");
  d.textContent = s;
  return d.innerHTML;
}

function initAuthPortal() {
  toggleKelasField();
  // Set welcome message based on returning user status
  const isReturning = localStorage.getItem("mbg_returning_user") === "1";
  const title = document.getElementById("auth-welcome-title");
  const sub = document.getElementById("auth-welcome-sub");
  if (title)
    title.textContent = isReturning
      ? "Selamat Datang Kembali!"
      : "Selamat Datang!";
  if (sub)
    sub.textContent = isReturning
      ? "Masuk ke akun Anda untuk melanjutkan"
      : "Masuk atau daftar untuk mulai menggunakan MBGue";
}

function setAuthTab(tab) {
  document.querySelectorAll(".auth-tab").forEach((t) => {
    t.classList.toggle("active", t.dataset.authTab === tab);
  });
  document.querySelectorAll(".auth-form").forEach((f) => {
    f.classList.toggle("active", f.id === `form-${tab}`);
  });

  // Update welcome heading based on active tab
  const isReturning = localStorage.getItem("mbg_returning_user") === "1";
  const title = document.getElementById("auth-welcome-title");
  const sub = document.getElementById("auth-welcome-sub");
  if (title && sub) {
    if (tab === "register") {
      title.textContent = "Buat Akun Baru";
      sub.textContent = "Daftar untuk mulai menggunakan MBGue";
    } else {
      title.textContent = isReturning
        ? "Selamat Datang Kembali!"
        : "Selamat Datang!";
      sub.textContent = isReturning
        ? "Masuk ke akun Anda untuk melanjutkan"
        : "Masuk atau daftar untuk mulai menggunakan MBGue";
    }
  }
}

function toggleKelasField() {
  const role = document.getElementById("reg-role")?.value;
  const wrap = document.getElementById("reg-kelas-wrap");
  const inp = document.getElementById("reg-kelas");
  if (!wrap || !inp) return;
  const show = role === "perwakilan_kelas";
  wrap.style.display = show ? "" : "none";
  inp.required = !!show;
}

async function submitLogin(ev) {
  ev.preventDefault();
  const email = document.getElementById("login-email")?.value?.trim();
  const password = document.getElementById("login-password")?.value || "";
  try {
    const res = await fetch("api/auth.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ action: "login", email, password }),
    });
    const j = await res.json().catch(() => ({}));
    console.log("Login response:", j, "Status:", res.status);
    if (j.status === "ok") {
      localStorage.setItem("mbg_returning_user", "1");
      // Simpan user data ke localStorage sebagai fallback untuk cross-port access
      localStorage.setItem("mbg_user_cache", JSON.stringify(j.user || {}));
      showToast("Berhasil masuk");
      // Tunggu lebih lama untuk memastikan session cookie tersimpan
      setTimeout(() => location.reload(), 1000);
    } else {
      showToast(j.pesan || "Gagal masuk");
    }
  } catch (err) {
    console.error("Login error:", err);
    showToast("Tidak terhubung ke server.");
  }
  return false;
}

async function submitRegister(ev) {
  ev.preventDefault();
  const nama = document.getElementById("reg-nama")?.value?.trim();
  const email = document.getElementById("reg-email")?.value?.trim();
  const password = document.getElementById("reg-password")?.value || "";
  const role = document.getElementById("reg-role")?.value;
  let kelas_info = document.getElementById("reg-kelas")?.value?.trim() || "";
  if (role !== "perwakilan_kelas") kelas_info = "";
  try {
    const res = await fetch("api/auth.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({
        action: "register",
        nama,
        email,
        password,
        role,
        kelas_info,
      }),
    });
    const j = await res.json().catch(() => ({}));
    console.log("Register response:", j, "Status:", res.status);
    if (j.status === "ok") {
      // Simpan user data ke localStorage sebagai fallback untuk cross-port access
      localStorage.setItem("mbg_user_cache", JSON.stringify(j.user || {}));
      showToast("Anda berhasil daftar");
      setTimeout(() => location.reload(), 1000);
    } else {
      showToast(
        j.pesan || "Pendaftaran gagal: " + (res.status || "unknown error"),
      );
    }
  } catch (err) {
    console.error("Register error:", err);
    showToast("Tidak terhubung ke server.");
  }
  return false;
}

async function logoutUser() {
  try {
    await fetch("api/auth.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ action: "logout" }),
    });
  } catch (_) {}
  // Clear localStorage cache
  localStorage.removeItem("mbg_user_cache");
  localStorage.removeItem("mbg_returning_user");
  location.reload();
}

function formatWeekInputValue(d) {
  const date = new Date(d.valueOf());
  date.setHours(0, 0, 0, 0);
  date.setDate(date.getDate() + 3 - ((date.getDay() + 6) % 7));
  const week1 = new Date(date.getFullYear(), 0, 4);
  const week =
    1 +
    Math.round(
      ((date.getTime() - week1.getTime()) / 86400000 -
        3 +
        ((week1.getDay() + 6) % 7)) /
        7,
    );
  const y = date.getFullYear();
  return `${y}-W${String(week).padStart(2, "0")}`;
}

function weekPickerToRange(value) {
  if (!value || !/^\d{4}-W\d{2}$/.test(value)) return null;
  const [ys, ws] = value.split("-W");
  const y = parseInt(ys, 10);
  const w = parseInt(ws, 10);
  const simple = new Date(y, 0, 1 + (w - 1) * 7);
  const dow = simple.getDay();
  const ISOweekStart = new Date(simple);
  if (dow <= 4) ISOweekStart.setDate(simple.getDate() - simple.getDay() + 1);
  else ISOweekStart.setDate(simple.getDate() + 8 - simple.getDay());
  const ISOweekEnd = new Date(ISOweekStart);
  ISOweekEnd.setDate(ISOweekStart.getDate() + 6);
  const fmt = (dt) => {
    const m = String(dt.getMonth() + 1).padStart(2, "0");
    const day = String(dt.getDate()).padStart(2, "0");
    return `${dt.getFullYear()}-${m}-${day}`;
  };
  return { mulai: fmt(ISOweekStart), selesai: fmt(ISOweekEnd) };
}

async function exportLaporanMingguan() {
  let weekVal = document.getElementById("filter-minggu")?.value;
  if (!weekVal) weekVal = formatWeekInputValue(new Date());
  const range = weekPickerToRange(weekVal);
  if (!range) {
    showToast("Pilih minggu laporan terlebih dahulu");
    return;
  }
  if (typeof XLSX === "undefined") {
    showToast("Pustaka Excel belum siap. Muat ulang halaman.");
    return;
  }
  try {
    const url = `api/laporan_minggu.php?mulai=${encodeURIComponent(range.mulai)}&selesai=${encodeURIComponent(range.selesai)}`;
    const res = await fetch(url, fetchCred);
    const data = await res.json().catch(() => null);
    if (!data || data.status !== "ok") {
      showToast(data?.pesan || "Gagal mengambil data mingguan");
      return;
    }
    const wb = XLSX.utils.book_new();
    const ringkas = [
      {
        Periode_mulai: data.periode.mulai,
        Periode_selesai: data.periode.selesai,
        Total_porsi: data.ringkasan.total_porsi_distribusi,
        Catatan_baris_ambil: data.ringkasan.catatan_kelas_ambil,
        Total_ompreng_kembali: data.ringkasan.total_ompreng_kembali,
        Persen_kembali: data.ringkasan.persen_kembali_vs_ambil + "%",
      },
    ];
    XLSX.utils.book_append_sheet(
      wb,
      XLSX.utils.json_to_sheet(ringkas),
      "Ringkasan",
    );
    const ambilRows = (data.detail_pengambilan || []).map((r) => ({
      Tanggal: r.tanggal,
      Jam: r.jam,
      Kelas: r.kelas,
      Jenjang: r.jenjang,
      Porsi: r.porsi_ambil,
      Waktu_ambil: r.waktu_ambil,
      Catatan: r.catatan || "",
    }));
    XLSX.utils.book_append_sheet(
      wb,
      XLSX.utils.json_to_sheet(
        ambilRows.length ? ambilRows : [{ Info: "Tidak ada pengambilan" }],
      ),
      "Pengambilan",
    );
    const kembaliRows = (data.detail_pengembalian || []).map((r) => ({
      Tanggal: r.tanggal,
      Kelas: r.kelas,
      Jenjang: r.jenjang,
      Jumlah_kembali: r.jumlah_kembali,
      Kondisi: r.kondisi,
      Persen: r.persen_kembali,
    }));
    XLSX.utils.book_append_sheet(
      wb,
      XLSX.utils.json_to_sheet(
        kembaliRows.length ? kembaliRows : [{ Info: "Tidak ada pengembalian" }],
      ),
      "Pengembalian",
    );
    const fname = `Laporan_MBG_${range.mulai}_${range.selesai}.xlsx`;
    XLSX.writeFile(wb, fname);
    showToast("File Excel berhasil diunduh");
  } catch (e) {
    console.error(e);
    showToast("Gagal mengekspor Excel");
  }
}

async function clearRekapHistory() {
  const role = window.SIAP_USER?.role || "";
  if (role !== "petugas_mbg") {
    showToast("Hanya petugas MBG yang dapat membersihkan riwayat rekap.");
    return;
  }

  const tanggal =
    document.getElementById("filter-tanggal")?.value || localISODate();
  if (!tanggal) {
    showToast("Tanggal rekap tidak tersedia untuk pembersihan");
    return;
  }

  const confirmed = window.confirm(
    `Hapus riwayat rekap untuk tanggal ${tanggal}? Data pengambilan dan pengembalian akan dihapus dari database.`,
  );
  if (!confirmed) return;

  try {
    const resp = await fetch("api/rekap_clear.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ tanggal }),
      ...fetchCred,
    });
    const data = await parseApiJson(resp);
    if (data.status !== "ok") {
      showToast(data.pesan || "Gagal membersihkan riwayat rekap");
      return;
    }
    showToast(data.pesan || "Riwayat rekap berhasil dibersihkan");
    renderRekap();
  } catch (err) {
    console.error(err);
    showToast("Gagal membersihkan riwayat rekap");
  }
}

function setFooterDate() {
  const opts = {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  };
  const now = new Date();
  const str = now.toLocaleDateString("id-ID", opts);
  const el = document.getElementById("footer-date");
  if (el) el.textContent = str;

  const chip = document.getElementById("date-chip");
  if (chip)
    chip.textContent = now.toLocaleDateString("id-ID", {
      day: "numeric",
      month: "short",
      year: "numeric",
    });

  const topSub = document.getElementById("topbar-sub");
  if (topSub) topSub.textContent = `MBGue · ${str}`;

  const greeting = document.getElementById("dashboard-greeting");
  if (greeting) greeting.textContent = getWIBGreeting();
}

function getWIBDate() {
  const now = new Date();
  const utcMillis = now.getTime() + now.getTimezoneOffset() * 60000;
  return new Date(utcMillis + 7 * 3600000);
}

function getWIBGreeting() {
  const hour = getWIBDate().getHours();
  if (hour >= 4 && hour < 10) return "Selamat Pagi";
  if (hour >= 10 && hour < 15) return "Selamat Siang";
  if (hour >= 15 && hour < 18) return "Selamat Sore";
  return "Selamat Malam";
}

function closeSidebar() {
  const sb = document.getElementById("sidebar");
  const overlay = document.getElementById("overlay");
  sb?.classList.remove("open");
  overlay?.classList.remove("show");
  if (!isSidebarMobile()) sb?.classList.add("collapsed");
}

function navigate(pageId, btn) {
  document
    .querySelectorAll(".page")
    .forEach((p) => p.classList.remove("active"));
  document
    .querySelectorAll(".sb-item")
    .forEach((b) => b.classList.remove("active"));

  const page = document.getElementById("page-" + pageId);
  if (page) page.classList.add("active");
  if (btn) btn.classList.add("active");

  const titles = {
    dashboard: "Dashboard",
    pengambilan: "Pengambilan MBG",
    pengembalian: "Pengembalian Ompreng",
    rekap: "Rekap & Laporan",
    quiz: "Quiz tebak menu",
    saran: "Saran & kritik",
    petugas: "Kelola",
  };
  document.getElementById("topbar-title").textContent = titles[pageId] || "";
  document
    .getElementById("content-wrap")
    ?.scrollTo({ top: 0, behavior: "smooth" });
  if (window.innerWidth <= 768) closeSidebar();
  if (pageId === "pengembalian") renderPendingList();
  if (pageId === "rekap") renderRekap();
  if (pageId === "dashboard") refreshDashboard();
  if (pageId === "saran") renderMasukanPage();
  if (pageId === "petugas") loadPetugasAdmin();
}

function initJenjangSMK() {
  State.jenjang = "SMK";
  const hid = document.getElementById("jenjang-value");
  if (hid) hid.value = "SMK";
  const tingkat = document.getElementById("kelas-tingkat");
  const huruf = document.getElementById("kelas-huruf");
  if (!tingkat || !huruf) return;
  tingkat.innerHTML = "";
  huruf.innerHTML = "";
  [
    [10, "Kelas 10"],
    [11, "Kelas 11"],
    [12, "Kelas 12"],
  ].forEach(([v, label]) => {
    const opt = document.createElement("option");
    opt.value = v;
    opt.textContent = label;
    tingkat.appendChild(opt);
  });
  const hurufOptions = [
    ["PPLG 1", "PPLG 1"],
    ["PPLG 2", "PPLG 2"],
    ["MPLB 1", "MPLB 1"],
    ["MPLB 2", "MPLB 2"],
    ["MPLB 3", "MPLB 3"],
    ["TO 1", "TO 1"],
    ["TO 2", "TO 2"],
    ["PM 1", "PM 1"],
    ["PM 2", "PM 2"],
    ["AKL 1", "AKL 1"],
    ["AKL 2", "AKL 2"],
    ["TKJ 1", "TKJ 1"],
    ["TKJ 2", "TKJ 2"],
  ];
  hurufOptions.forEach(([v, label]) => {
    const opt = document.createElement("option");
    opt.value = v;
    opt.textContent = label;
    huruf.appendChild(opt);
  });
  updateMathInline();
}

function adjSiswa(delta) {
  State.siswa = Math.max(1, State.siswa + delta);
  document.getElementById("cnt-siswa").textContent = State.siswa;
  updateMathInline();
}

function adjOmpreng(delta) {
  const selected = getSelectedPengambilan();
  const maxCount = selected ? selected.jumlah : 0;
  State.ompreng = Math.max(0, Math.min(maxCount, State.ompreng + delta));
  document.getElementById("cnt-ompreng").textContent = State.ompreng;
  updateRetMath();
}

function updateMathInline() {
  const tingkat = document.getElementById("kelas-tingkat")?.value || "10";
  const huruf = document.getElementById("kelas-huruf")?.value || "X-A";
  const kelasLabel = `${State.jenjang} ${tingkat}-${huruf}`;
  const berat = Math.round(State.siswa * 0.5);
  safeSet("mi-kelas", kelasLabel);
  safeSet("mi-siswa", State.siswa);
  safeSet("mi-berat", berat);
}

function updateRetMath() {
  const kelasId = document.getElementById("ret-kelas")?.value;
  const selected = getSelectedPengambilan();
  const total = selected ? Number(selected.jumlah) : State.ompreng;
  if (selected && State.ompreng > total) State.ompreng = total;
  document.getElementById("cnt-ompreng").textContent = State.ompreng;
  const pct = total > 0 ? Math.round((State.ompreng / total) * 100) : 0;
  safeSet("ret-math", `${State.ompreng} / ${total} × 100% = ${pct}%`);
}

function getSelectedPengambilan() {
  const kelasId = document.getElementById("ret-kelas")?.value;
  if (!kelasId) return null;
  return (
    State.pengambilan.find((p) => String(p.id) === String(kelasId)) || null
  );
}

function selectKondisi(val, label) {
  State.kondisi = val;
  document
    .querySelectorAll(".kondisi-check")
    .forEach((c) => c.classList.remove("active"));
  document
    .querySelectorAll(".kondisi-opt")
    .forEach((o) => o.classList.remove("selected"));
  if (label) {
    label.querySelector(".kondisi-check")?.classList.add("active");
    label.classList.add("selected");
  }
}

function formatStampWaktu() {
  return new Date().toLocaleString("id-ID", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function getStampAmbil() {
  const tingkat = document.getElementById("kelas-tingkat")?.value || "10";
  const huruf = document.getElementById("kelas-huruf")?.value || "A";
  const kelasLabel = `${State.jenjang} ${tingkat}-${huruf}`;
  return `${kelasLabel} | ${State.siswa} porsi | ${formatStampWaktu()}`;
}

function getStampRet() {
  const selected = getSelectedPengambilan();
  const kelasLabel = selected ? selected.kelas : "Kelas";
  const kondisi = State.kondisi.toUpperCase();
  return `${kelasLabel} | Kembali: ${State.ompreng} | ${kondisi} | ${formatStampWaktu()}`;
}

function updateCamLiveStamp() {
  const el = document.getElementById("cam-live-stamp");
  if (!el) return;
  el.textContent = camMode === "ret" ? getStampRet() : getStampAmbil();
}

function stopCamStream() {
  if (camStream) {
    camStream.getTracks().forEach((t) => t.stop());
    camStream = null;
  }
  const video = document.getElementById("cam-video");
  if (video) video.srcObject = null;
}

function closeCamModal() {
  stopCamStream();
  const modal = document.getElementById("cam-modal");
  if (modal) {
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
  }
  camMode = null;
  document.body.classList.remove("cam-modal-open");
}

async function openCamModal(mode) {
  if (!navigator.mediaDevices?.getUserMedia) {
    showToast("Browser tidak mendukung kamera langsung");
    document.getElementById("cam-input")?.click();
    return;
  }

  camMode = mode;
  const modal = document.getElementById("cam-modal");
  const video = document.getElementById("cam-video");
  const title = document.getElementById("cam-modal-title");
  if (!modal || !video) return;

  if (title) {
    title.textContent =
      mode === "ret" ? "Foto Bukti Pengembalian" : "Ambil Foto Bukti";
  }
  updateCamLiveStamp();

  try {
    stopCamStream();
    camStream = await navigator.mediaDevices.getUserMedia({
      video: {
        facingMode: { ideal: "environment" },
        width: { ideal: 1280 },
        height: { ideal: 720 },
      },
      audio: false,
    });
    video.srcObject = camStream;
    await video.play();
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("cam-modal-open");
  } catch (err) {
    console.error(err);
    camMode = null;
    showToast("Tidak dapat mengakses kamera. Izinkan akses kamera di browser.");
  }
}

function openCamera() {
  openCamModal("ambil");
}

function openCameraRet() {
  openCamModal("ret");
}

function captureFromCamera() {
  const video = document.getElementById("cam-video");
  const btn = document.getElementById("cam-btn-capture");
  if (!video?.videoWidth) {
    showToast("Kamera belum siap");
    return;
  }

  const mode = camMode;
  const stampText = mode === "ret" ? getStampRet() : getStampAmbil();
  if (btn) btn.disabled = true;

  kompresiDariSumber(video, stampText, FOTO_MAX_W, FOTO_QUALITY, (dataURL) => {
    if (btn) btn.disabled = false;
    closeCamModal();
    if (mode === "ret") applyFotoRet(dataURL, stampText);
    else applyFotoAmbil(dataURL, stampText);
  });
}

function applyFotoAmbil(dataURL, stampText) {
  State.fotoDataURL = dataURL;
  const zone = document.getElementById("cam-zone");
  if (zone) {
    zone.classList.add("done");
    const label = zone.querySelector(".cam-label");
    const hint = zone.querySelector(".cam-hint");
    if (label) label.textContent = "Foto berhasil diambil";
    if (hint) hint.textContent = "Klik untuk ganti foto";
  }
  const result = document.getElementById("foto-result");
  if (result) {
    result.style.display = "block";
    document.getElementById("foto-preview").src = dataURL;
    document.getElementById("foto-stamp").textContent = stampText;
  }
}

function applyFotoRet(dataURL, stampText) {
  State.fotoRetDataURL = dataURL;
  const label = document.getElementById("cam-ret-label");
  if (label) label.textContent = "Foto berhasil diambil";
  const prev = document.getElementById("cam-ret-preview");
  if (prev) {
    prev.src = dataURL;
    prev.style.display = "block";
  }
}

function handleFoto(event) {
  const file = event.target.files[0];
  if (!file) return;
  const stampText = getStampAmbil();
  kompresiFoto(file, stampText, FOTO_MAX_W, FOTO_QUALITY, (dataURL) => {
    applyFotoAmbil(dataURL, stampText);
  });
  event.target.value = "";
}

function handleFotoRet(event) {
  const file = event.target.files[0];
  if (!file) return;
  const stampText = getStampRet();
  kompresiFoto(file, stampText, FOTO_MAX_W, FOTO_QUALITY, (dataURL) => {
    applyFotoRet(dataURL, stampText);
  });
  event.target.value = "";
}

function handleDok(event) {
  const file = event.target.files[0];
  if (!file) return;
  document.getElementById("dok-label").textContent = file.name;
}

function gambarStempel(ctx, canvas, stampText) {
  const fh = Math.max(13, Math.round(canvas.width / 42));
  const barH = fh + 18;
  ctx.fillStyle = "rgba(13,71,161,0.85)";
  ctx.fillRect(0, canvas.height - barH, canvas.width, barH);
  ctx.fillStyle = "#fff";
  ctx.font = `bold ${fh}px "Plus Jakarta Sans", "Segoe UI", sans-serif`;
  ctx.fillText(stampText, 10, canvas.height - 8);
}

function kompresiDariSumber(source, stampText, maxW, quality, callback) {
  const w = source.videoWidth || source.naturalWidth || source.width;
  const h = source.videoHeight || source.naturalHeight || source.height;
  if (!w || !h) return;
  const scale = Math.min(1, maxW / w);
  const canvas = document.createElement("canvas");
  canvas.width = Math.round(w * scale);
  canvas.height = Math.round(h * scale);
  const ctx = canvas.getContext("2d");
  ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
  gambarStempel(ctx, canvas, stampText);
  callback(canvas.toDataURL("image/jpeg", quality));
}

function kompresiFoto(file, stampText, maxW, quality, callback) {
  const reader = new FileReader();
  reader.onload = (ev) => {
    const img = new Image();
    img.onload = () =>
      kompresiDariSumber(img, stampText, maxW, quality, callback);
    img.src = ev.target.result;
  };
  reader.readAsDataURL(file);
}

async function loadData() {
  try {
    const today = localISODate();
    const [ambilResp, kembaliResp] = await Promise.all([
      fetch(
        `api/pengambilan.php?tanggal=${encodeURIComponent(today)}`,
        fetchCred,
      ),
      fetch(
        `api/pengembalian.php?tanggal=${encodeURIComponent(today)}`,
        fetchCred,
      ),
    ]);
    const ambilData = await parseApiJson(ambilResp);
    const kembaliData = await parseApiJson(kembaliResp);

    State.pengambilan = (ambilData.data || []).map((r) => ({
      ...r,
      foto: r.foto ? `uploads/foto/${r.foto}` : "",
      kembali: 0,
      statusKembali: false,
    }));
    State.pengembalian = (kembaliData.data || []).map((r) => ({
      ...r,
      foto: r.foto ? `uploads/foto/${r.foto}` : "",
    }));

    const returnCounts = {};
    State.pengembalian.forEach((r) => {
      const key = String(r.id_pengambilan || r.pengambilan_id || "");
      returnCounts[key] =
        (returnCounts[key] || 0) + Number(r.jumlah_kembali || 0);
    });
    State.pengambilan.forEach((r) => {
      const key = String(r.id || "");
      const jumlah = Number(r.jumlah_ambil ?? r.jumlah ?? 0);
      r.kembali = returnCounts[key] || 0;
      r.statusKembali = jumlah > 0 ? r.kembali >= jumlah : false;
    });

    populateRetOptions();
    refreshDashboard();
    renderPendingList();
    renderRekap();
    await loadNotifications();
  } catch (err) {
    console.error(err);
    showToast("Gagal memuat data. Periksa koneksi ke server.");
  }
}

function populateRetOptions() {
  const sel = document.getElementById("ret-kelas");
  if (!sel) return;
  sel.innerHTML = '<option value="">— Pilih Kelas —</option>';
  const pending = State.pengambilan.filter(
    (r) => Number(r.jumlah_ambil ?? r.jumlah ?? 0) > Number(r.kembali ?? 0),
  );
  pending.forEach((r) => {
    const opt = document.createElement("option");
    opt.value = String(r.id);
    opt.textContent = `${r.kelas} (${r.jenjang || ""}) — ${r.jumlah_ambil || r.jumlah} porsi`;
    sel.appendChild(opt);
  });
}

async function submitPengambilan() {
  const tingkat = document.getElementById("kelas-tingkat")?.value || "10";
  const huruf = document.getElementById("kelas-huruf")?.value || "X-A";
  const waktu = document.getElementById("waktu-ambil")?.value || "";
  const catatan = document.getElementById("catatan-ambil")?.value || "";
  if (!State.fotoDataURL) {
    showToast("Harap tangkap foto bukti pengambilan terlebih dahulu");
    return;
  }
  const kelasLabel = `${State.jenjang} ${tingkat}-${huruf}`;
  const payload = {
    kelas: kelasLabel,
    jenjang: State.jenjang,
    jumlah: State.siswa,
    waktu,
    catatan,
    foto: State.fotoDataURL,
  };

  const res = await kirimKeBackend("api/pengambilan.php", payload);
  if (res?.status === "ok") {
    showToast("Pengambilan berhasil dicatat");
    resetFormPengambilan();
    await loadData();
    navigate("rekap", document.querySelector("[data-page=rekap]"));
  } else {
    showToast(res?.pesan || "Gagal menyimpan pengambilan");
  }
}

function resetFormPengambilan() {
  State.fotoDataURL = null;
  State.siswa = 36;
  document.getElementById("cnt-siswa").textContent = 36;
  document.getElementById("foto-result").style.display = "none";
  document.getElementById("foto-preview").src = "";
  document.getElementById("cam-input").value = "";
  document.getElementById("catatan-ambil").value = "";
  const zone = document.getElementById("cam-zone");
  if (zone) {
    zone.classList.remove("done");
    const label = zone.querySelector(".cam-label");
    const hint = zone.querySelector(".cam-hint");
    if (label) label.textContent = "Buka Kamera";
    if (hint) hint.textContent = "Foto langsung dikompres & diberi stempel";
  }
  updateMathInline();
}

async function submitPengembalian() {
  const kelasId = document.getElementById("ret-kelas")?.value;
  if (!kelasId) {
    showToast("Pilih kelas terlebih dahulu");
    return;
  }
  const selected = getSelectedPengambilan();
  if (!selected) {
    showToast("Kelas tidak ditemukan");
    return;
  }
  const payload = {
    idPengambilan: Number(kelasId),
    kelas: selected.kelas,
    ompreng: State.ompreng,
    kondisi: State.kondisi,
  };

  const res = await kirimKeBackend("api/pengembalian.php", payload);
  if (res?.status === "ok") {
    showToast("Pengembalian berhasil dicatat");
    State.fotoRetDataURL = null;
    document
      .getElementById("cam-ret-preview")
      ?.style.setProperty("display", "none");
    if (document.getElementById("cam-ret-input")) {
      document.getElementById("cam-ret-input").value = "";
    }
    State.ompreng = selected.jumlah_ambil || selected.jumlah || 0;
    document.getElementById("cnt-ompreng").textContent = State.ompreng;
    await loadData();
    navigate("rekap", document.querySelector("[data-page=rekap]"));
  } else {
    showToast(res?.pesan || "Gagal menyimpan pengembalian");
  }
}

function refreshDashboard() {
  const totalPorsi = State.pengambilan.reduce(
    (sum, r) => sum + Number(r.jumlah_ambil ?? r.jumlah ?? 0),
    0,
  );
  const kelasSudah = State.pengambilan.length;
  const pendingClasses = State.pengambilan.filter(
    (r) => Number(r.jumlah_ambil ?? r.jumlah ?? 0) > Number(r.kembali ?? 0),
  );
  const belumKembali = pendingClasses.length;
  // Total pending quantity (sum of remaining ompreng per class)
  const pendingQty = State.pengambilan.reduce((sum, r) => {
    const jumlah = Number(r.jumlah_ambil ?? r.jumlah ?? 0);
    const kembali = Number(r.kembali ?? 0);
    return sum + Math.max(0, jumlah - kembali);
  }, 0);
  const totalRet = State.pengembalian.reduce(
    (sum, r) => sum + Number(r.jumlah_kembali || 0),
    0,
  );
  const pct = totalPorsi > 0 ? Math.round((totalRet / totalPorsi) * 100) : 0;

  safeSet("s-total", totalPorsi);
  safeSet("s-kelas", kelasSudah);
  safeSet("s-kelas-sub", `dari ${kelasSudah} kelas tercatat`);
  // Sidebar badges and topbar — show quantities (total pending ompreng)
  safeSet("badge-pengambilan", kelasSudah);
  safeSet("badge-pengembalian", pendingQty);
  safeSet("s-belum", pendingQty);
  safeSet("topbar-notif", pendingQty);
  safeSet("prog-pct", `${pct}%`);

  updateNotifPanelContent();
  loadCharts();

  const progList = document.getElementById("prog-list");
  if (!progList) return;
  if (State.pengambilan.length === 0) {
    progList.innerHTML =
      '<div class="empty-state">Belum ada data pengambilan hari ini</div>';
  } else {
    progList.innerHTML = State.pengambilan
      .map((r) => {
        const done = r.statusKembali;
        const jumlah = Number(r.jumlah_ambil || r.jumlah || 0);
        return `
        <div class="prog-row">
          <div class="prog-meta">
            <span class="prog-name">${r.kelas}</span>
            <span class="prog-pct">${jumlah} porsi ${done ? "✓" : ""}</span>
          </div>
          <div class="prog-bar"><div class="prog-fill${done ? " done" : ""}" style="width:${done ? 100 : Math.min(100, Math.round(jumlah > 0 ? (r.kembali / jumlah) * 100 : 0))}%"></div></div>
        </div>`;
      })
      .join("");
  }

  const actList = document.getElementById("activity-list");
  if (!actList) return;

  const activities = [
    ...State.pengambilan.map((r) => ({
      text: `${r.kelas} mengambil ${r.jumlah_ambil || r.jumlah} porsi MBG`,
      time: r.created_at || r.tanggal || "",
      color: "#1565C0",
    })),
    ...State.pengembalian.map((r) => ({
      text: `${r.kelas} mengembalikan ${r.jumlah_kembali} ompreng (${r.kondisi})`,
      time: r.created_at || r.tanggal || "",
      color: "#2E7D32",
    })),
  ].sort((a, b) => new Date(b.time) - new Date(a.time));

  if (activities.length === 0) {
    actList.innerHTML =
      '<div class="empty-state">Belum ada aktivitas hari ini</div>';
  } else {
    actList.innerHTML = activities
      .slice(0, 6)
      .map(
        (a) => `
      <div class="act-item">
        <div class="act-dot" style="background:${a.color}"></div>
        <div class="act-body">
          <div class="act-text">${a.text}</div>
          <div class="act-time">${formatTime(a.time)}</div>
        </div>
      </div>`,
      )
      .join("");
  }
}

function renderPendingList() {
  const container = document.getElementById("pending-list");
  if (!container) return;
  const pending = State.pengambilan.filter((r) => !r.statusKembali);
  if (pending.length === 0) {
    container.innerHTML =
      '<div class="empty-state" style="padding:40px 0">Semua kelas sudah mengembalikan ompreng</div>';
    return;
  }
  container.innerHTML = pending
    .map((r) => {
      const jumlah = Number(r.jumlah_ambil || r.jumlah || 0);
      const kembali = Number(r.kembali || 0);
      const sisa = Math.max(0, jumlah - kembali);
      return `
    <div class="pending-card">
      <div class="pending-icon">
        <svg viewBox="0 0 24 24"><path d="M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62c1.39-1.16 3.16-1.88 5.12-1.88 3.54 0 6.55 2.31 7.6 5.5l2.37-.78C21.08 11.03 17.15 8 12.5 8z"/></svg>
      </div>
      <div class="pending-info">
        <div class="pending-name">${r.kelas}</div>
        <div class="pending-meta">${sisa} ompreng belum kembali · Diambil ${formatTime(r.created_at || r.tanggal || "")}</div>
      </div>
      <button class="pending-action" onclick="quickRet('${r.id}')">Catat Kembali</button>
    </div>`;
    })
    .join("");
}

function quickRet(id) {
  const sel = document.getElementById("ret-kelas");
  if (!sel) return;
  sel.value = id;
  const selected = getSelectedPengambilan();
  if (selected) {
    State.ompreng = Number(selected.jumlah_ambil || selected.jumlah || 0);
    document.getElementById("cnt-ompreng").textContent = State.ompreng;
  }
  updateRetMath();
  document.getElementById("ret-kelas")?.scrollIntoView({ behavior: "smooth" });
}

async function renderRekap() {
  try {
    const tanggal =
      document.getElementById("filter-tanggal")?.value || localISODate();
    const jenis = document.getElementById("filter-jenis")?.value || "semua";
    const [ambilResp, kembaliResp] = await Promise.all([
      fetch(
        `api/pengambilan.php?tanggal=${encodeURIComponent(tanggal)}`,
        fetchCred,
      ),
      fetch(
        `api/pengembalian.php?tanggal=${encodeURIComponent(tanggal)}`,
        fetchCred,
      ),
    ]);
    const ambilData = await parseApiJson(ambilResp);
    const kembaliData = await parseApiJson(kembaliResp);

    const summaryEl = document.getElementById("rekap-summary");
    const tbody = document.getElementById("rekap-tbody");
    if (!summaryEl || !tbody) return;

    if (ambilData.status === "error" || kembaliData.status === "error") {
      showToast(
        ambilData.pesan ||
          kembaliData.pesan ||
          "Gagal memuat rekap — periksa database / URL aplikasi",
      );
    }

    const totalAmbil = ambilData.total_porsi || 0;
    const totalKembali = kembaliData.total_kembali || 0;
    const pct = kembaliData.rata_persen || 0;
    summaryEl.innerHTML = `
      <div class="sum-chip"><strong>${ambilData.data?.length || 0}</strong> Kelas Ambil MBG</div>
      <div class="sum-chip"><strong>${totalAmbil}</strong> Total Porsi</div>
      <div class="sum-chip"><strong>${kembaliData.data?.length || 0}</strong> Pengembalian</div>
      <div class="sum-chip"><strong>${pct}%</strong> Tingkat Pengembalian</div>`;

    const returnMap = {};
    (kembaliData.data || []).forEach((r) => {
      const key = String(r.id_pengambilan || r.pengambilan_id || "");
      if (!key) return;
      returnMap[key] = r;
    });

    const rows = [];
    (ambilData.data || []).forEach((r) => {
      const kembali = returnMap[String(r.id)] || null;
      if (jenis === "pengembalian" && !kembali) return;

      const jumlah = Number(r.jumlah || r.jumlah_ambil || 0);
      const foto = kembali?.foto
        ? `uploads/foto/${kembali.foto}`
        : r.foto
          ? `uploads/foto/${r.foto}`
          : "";
      const kondisi = kembali?.kondisi || "belum kembali";
      const kondisiLabel =
        kondisi === "belum kembali"
          ? "Belum Kembali"
          : kondisi.charAt(0).toUpperCase() + kondisi.slice(1);
      const badgeClass = kembali
        ? kondisi === "baik"
          ? "badge-blue"
          : kondisi === "kotor"
            ? "badge-orange"
            : kondisi === "rusak"
              ? "badge-red"
              : "badge-blue"
        : "badge-orange";
      rows.push({
        waktu: r.created_at || r.tanggal || "",
        jenis: kembali ? "Pengembalian" : "Pengambilan",
        label: r.kelas || r.kelas_ambil || "",
        jumlah: `${jumlah} porsi`,
        status: `<span class="badge ${badgeClass}">${kondisiLabel}</span>`,
        foto,
      });
    });

    if (!rows.length) {
      tbody.innerHTML =
        '<tr><td colspan="6" class="empty-td">Belum ada data untuk ditampilkan</td></tr>';
    } else {
      tbody.innerHTML = rows
        .map(
          (r) => `
        <tr>
          <td>${formatTime(r.waktu)}</td>
          <td><span class="badge ${r.jenis === "Pengembalian" ? "badge-green" : "badge-blue"}">${r.jenis}</span></td>
          <td>${r.label}</td>
          <td class="rekap-jumlah">${r.jumlah}</td>
          <td>${r.status}</td>
          <td>${
            r.foto
              ? `<button type="button" class="rekap-foto-btn" data-foto="${escapeAttr(r.foto)}" title="Perbesar foto" aria-label="Perbesar foto bukti">
                  <img src="${escapeAttr(r.foto)}" alt="Foto bukti" class="rekap-foto-thumb" loading="lazy">
                </button>`
              : "—"
          }</td>
        </tr>`,
        )
        .join("");
    }
  } catch (err) {
    console.error(err);
    showToast("Gagal memuat rekap.");
  }
}

function escapeAttr(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/</g, "&lt;");
}

function openLightbox(src) {
  const img = document.getElementById("lightbox-img");
  const box = document.getElementById("lightbox");
  if (!img || !box || !src) return;
  img.src = src;
  img.alt = "Foto bukti";
  box.classList.add("open");
  document.body.classList.add("lightbox-open");
}

function closeLightbox() {
  const box = document.getElementById("lightbox");
  const img = document.getElementById("lightbox-img");
  box?.classList.remove("open");
  document.body.classList.remove("lightbox-open");
  if (img) img.src = "";
}

async function kirimKeBackend(endpoint, data) {
  try {
    const res = await fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify(data),
    });
    const json = await res.json().catch(() => null);
    if (!res.ok) {
      showToast(json?.pesan || `Gagal tersambung ke server (${res.status})`);
      return json;
    }
    return json;
  } catch (err) {
    console.error(err);
    showToast("Server tidak tersedia. Coba lagi setelah terhubung.");
    return null;
  }
}

function showToast(msg) {
  const el = document.getElementById("toast");
  if (!el) return;
  el.textContent = msg;
  el.classList.add("show");
  setTimeout(() => el.classList.remove("show"), 2800);
}

function toggleNotifPanel(ev) {
  if (ev) ev.stopPropagation();
  const panel = document.getElementById("notif-dropdown");
  const btn = document.getElementById("notif-toggle");
  if (!panel) return;
  const open = panel.classList.toggle("open");
  btn?.setAttribute("aria-expanded", open ? "true" : "false");
}

function safeSet(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

function formatTime(value) {
  if (!value) return "—";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
}

function chartFontColor() {
  const dark = document.documentElement.getAttribute("data-theme") === "dark";
  return dark ? "#e8eef5" : "#0f172a";
}

function chartGridColor() {
  const dark = document.documentElement.getAttribute("data-theme") === "dark";
  return dark ? "rgba(148, 212, 255, 0.1)" : "rgba(15, 23, 42, 0.07)";
}

async function loadCharts() {
  const el = document.getElementById("chart-mbg-minggu");
  if (!el || typeof Chart === "undefined") return;

  let payload = null;
  try {
    const res = await fetch("api/chart_minggu_kerja.php", fetchCred);
    payload = await parseApiJson(res);
  } catch (_) {}

  const font = chartFontColor();
  const grid = chartGridColor();

  const labels =
    payload?.status === "ok" && Array.isArray(payload.labels)
      ? payload.labels
      : ["Sen", "Sel", "Rab", "Kam", "Jum"];

  const stackAmbil =
    payload?.status === "ok" && Array.isArray(payload.pengambilan_stack)
      ? payload.pengambilan_stack
      : [{ label: "Belum ada data", data: [0, 0, 0, 0, 0] }];

  const hariRingkas =
    payload?.status === "ok" && Array.isArray(payload.hari_ringkas)
      ? payload.hari_ringkas
      : Array.from({ length: 5 }, () => ({
          total_ambil: 0,
          total_kembali: 0,
          persen_kembali: 0,
        }));

  const barDatasets = stackAmbil.map((ds) => ({
    type: "bar",
    label: ds.label,
    data: ds.data,
    backgroundColor: ds.backgroundColor || "#0ea5e9",
    stack: "ambil",
  }));

  const maxPersenAxis = Math.max(
    100,
    ...hariRingkas.map((h) => Number(h.persen_kembali ?? 0)),
  );

  const datasets = [
    ...barDatasets,
    {
      type: "line",
      label: "Porsi pengembalian",
      data: hariRingkas.map((h) => Number(h.total_kembali ?? 0)),
      borderColor: "#f59e0b",
      backgroundColor: "rgba(245, 158, 11, 0.08)",
      borderWidth: 2,
      tension: 0.35,
      fill: false,
      pointRadius: 4,
      pointHoverRadius: 6,
      order: 10,
    },
  ];

  if (chartMbgMinggu) chartMbgMinggu.destroy();
  chartMbgMinggu = new Chart(el.getContext("2d"), {
    type: "bar",
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: "index", intersect: false },
      datasets: {
        bar: {
          categoryPercentage: 0.62,
          barPercentage: 0.82,
          borderRadius: 5,
          borderSkipped: false,
        },
      },
      scales: {
        x: {
          stacked: true,
          ticks: {
            color: font,
            maxRotation: 0,
            font: { size: 11, weight: "600" },
          },
          grid: { display: false },
        },
        y: {
          stacked: true,
          beginAtZero: true,
          ticks: { color: font, font: { size: 11 } },
          grid: { color: grid },
          title: {
            display: true,
            text: "Porsi ambil & pengembalian",
            color: font,
            font: { size: 11, weight: "600" },
          },
        },
      },
      plugins: {
        legend: {
          position: "bottom",
          labels: {
            color: font,
            padding: 8,
            font: { size: 10, weight: "600" },
            boxWidth: 11,
          },
        },
        tooltip: {
          callbacks: {
            footer(items) {
              if (!items?.length) return "";
              const idx = items[0].dataIndex;
              const hk = hariRingkas[idx];
              if (!hk) return "";
              return [
                "─────────────",
                `Total ambil: ${hk.total_ambil} porsi`,
                `Ompreng kembali: ${hk.total_kembali}`,
                `Rasio (kembali ÷ ambil): ${hk.persen_kembali}%`,
              ].join("\n");
            },
          },
        },
      },
    },
  });
}

/* ---------- Quiz · Saran · Admin petugas ---------- */

let QuizBundle = [];

async function startQuizMBG() {
  const area = document.getElementById("quiz-area");
  const fb = document.getElementById("quiz-feedback");
  if (!area) return;
  fb.style.display = "none";
  area.style.display = "block";
  area.innerHTML = '<p class="quiz-loading">Memuat soal…</p>';
  try {
    const res = await fetch("api/quiz.php?action=bundle", fetchCred);
    const j = await parseApiJson(res);
    if (j.status !== "ok" || !Array.isArray(j.bundle)) {
      showToast(j.pesan || "Gagal memuat quiz");
      area.innerHTML = "";
      area.style.display = "none";
      return;
    }
    QuizBundle = j.bundle;
    area.innerHTML =
      QuizBundle.map((soal, idx) => {
        const opts = (soal.opsi || [])
          .map(
            (op) =>
              `<label class="quiz-opt"><input type="radio" name="q_${soal.id}" value="${escapeHtmlAttr(op)}"><span>${escapeHtml(op)}</span></label>`,
          )
          .join("");
        return `<div class="quiz-soal" data-qid="${soal.id}">
        <div class="quiz-soal-num">Soal ${idx + 1} / ${QuizBundle.length}</div>
        <p class="quiz-petunjuk">${escapeHtml(soal.petunjuk)}</p>
        <div class="quiz-opsi-group">${opts}</div>
      </div>`;
      }).join("") +
      `<button type="button" class="btn-submit quiz-kirim" onclick="submitQuizMBG()">Kirim jawaban</button>`;
  } catch (e) {
    console.error(e);
    showToast("Tidak dapat memuat quiz");
    area.style.display = "none";
  }
}

function escapeHtmlAttr(s) {
  return String(s)
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/</g, "&lt;");
}

async function submitQuizMBG() {
  const jawaban = [];
  for (const soal of QuizBundle) {
    const picked = document.querySelector(`input[name="q_${soal.id}"]:checked`);
    jawaban.push({
      id: soal.id,
      pilihan: picked ? picked.value : "",
    });
  }
  try {
    const res = await fetch("api/quiz.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ action: "submit", jawaban }),
    });
    const j = await parseApiJson(res);
    if (j.status !== "ok") {
      showToast(j.pesan || "Gagal mengirim jawaban");
      return;
    }
    const area = document.getElementById("quiz-area");
    if (area) area.style.display = "none";
    await tampilkanFeedbackQuizHarian(j.pesan);
    scrollToQuizFeedbackSmooth();
    showToast(j.pesan || "Terima kasih!");
  } catch (e) {
    console.error(e);
    showToast("Kirim jawaban gagal");
  }
}

function scrollToQuizFeedbackSmooth() {
  const fb = document.getElementById("quiz-feedback");
  const wrap = document.getElementById("content-wrap");
  const topbar = document.getElementById("topbar");
  if (!fb || !wrap) return;
  fb.style.display = "block";
  const tbH = topbar?.offsetHeight || 56;
  document.documentElement.style.setProperty("--topbar-h", `${tbH}px`);
  window.requestAnimationFrame(() => {
    window.requestAnimationFrame(() => {
      setTimeout(() => {
        const rect = fb.getBoundingClientRect();
        const top = wrap.scrollTop + rect.top - tbH - 16;
        wrap.scrollTo({ top: Math.max(0, top), behavior: "smooth" });
        fb.focus({ preventScroll: true });
      }, 50);
    });
  });
}

async function tampilkanFeedbackQuizHarian(ringkasanSkor) {
  const fb = document.getElementById("quiz-feedback");
  const kicker = document.getElementById("quiz-feedback-kicker");
  const title = document.getElementById("quiz-feedback-title");
  const menuWrap = document.getElementById("quiz-feedback-menu-wrap");
  const menuEl = document.getElementById("quiz-feedback-menu");
  const bodyEl = document.getElementById("quiz-feedback-body");
  if (!fb || !kicker || !title || !menuWrap || !menuEl || !bodyEl) return;

  fb.style.display = "block";
  kicker.textContent = "Feedback untuk hari Anda bermain quiz";

  try {
    const res = await fetch("api/quiz_feedback_harian.php", fetchCred);
    const j = await parseApiJson(res);
    if (j.status !== "ok") {
      title.textContent = "Feedback";
      menuWrap.style.display = "none";
      bodyEl.textContent =
        "Feedback belum diatur petugas. " + (ringkasanSkor || "");
      return;
    }

    title.textContent = j.hari_label ? `Hari ${j.hari_label}` : "Feedback";

    const menu = (j.menu_hari || "").trim();
    if (menu) {
      menuWrap.style.display = "block";
      menuEl.textContent = menu;
    } else {
      menuWrap.style.display = "none";
      menuEl.textContent = "";
    }

    let pesan = (j.pesan_feedback || "").trim();
    if (!pesan) {
      pesan =
        "Petugas belum menambahkan pesan khusus untuk hari ini. Tetap semangat ikut program MBG!";
    }
    if (ringkasanSkor) {
      pesan = `${ringkasanSkor}\n\n${pesan}`;
    }
    bodyEl.textContent = pesan;
  } catch (_) {
    title.textContent = "Feedback";
    menuWrap.style.display = "none";
    bodyEl.textContent =
      "Tidak dapat memuat feedback harian. " + (ringkasanSkor || "");
  }
}

async function deleteNotification(id) {
  if (!confirm("Hapus notifikasi ini?")) return;
  try {
    const res = await fetch("api/notifikasi.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ action: "delete", id }),
    });
    const j = await parseApiJson(res);
    if (j.status === "ok") {
      await loadNotifications();
      showToast(j.pesan || "Notifikasi dihapus");
    } else {
      showToast(j.pesan || "Gagal menghapus notifikasi");
    }
  } catch (_) {
    showToast("Gagal menghapus notifikasi");
  }
}

async function kirimSaran() {
  const role = window.SIAP_USER?.role || "";
  if (!["perwakilan_kelas", "petugas_mbg"].includes(role)) {
    showToast(
      "Hanya perwakilan kelas dan petugas MBG yang dapat mengirim masukan.",
    );
    return;
  }
  const jenis = document.getElementById("saran-jenis")?.value || "saran_menu";
  const isi = document.getElementById("saran-isi")?.value?.trim() || "";
  if (isi.length < 5) {
    showToast("Isi pesan minimal 5 karakter");
    return;
  }
  try {
    const res = await fetch("api/saran.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ jenis, isi }),
    });
    const j = await parseApiJson(res);
    showToast(j.pesan || (j.status === "ok" ? "Terkirim" : "Gagal"));
    if (j.status === "ok") {
      document.getElementById("saran-isi").value = "";
      loadNotifications();
      renderMasukanPage();
    }
  } catch (_) {
    showToast("Gagal mengirim");
  }
}

async function loadPetugasAdmin() {
  if (!["petugas_mbg", "dapur_sppg"].includes(window.SIAP_USER?.role || ""))
    return;
  await Promise.all([
    adminLoadUsers(),
    adminLoadQuiz(),
    adminLoadSaran(),
    adminPrefillJadwal(),
    adminLoadFeedbackHarian(),
  ]);
}

async function adminLoadFeedbackHarian() {
  try {
    const res = await fetch("api/quiz_feedback_harian.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ action: "list_all" }),
    });
    const j = await parseApiJson(res);
    if (j.status !== "ok" || !Array.isArray(j.data)) return;
    j.data.forEach((row) => {
      const m = document.querySelector(`.fb-menu[data-hari="${row.hari}"]`);
      const p = document.querySelector(`.fb-pesan[data-hari="${row.hari}"]`);
      if (m) m.value = row.menu_hari || "";
      if (p) p.value = row.pesan_feedback || "";
    });
  } catch (_) {}
}

async function simpanFeedbackHarianAdmin() {
  const keys = ["senin", "selasa", "rabu", "kamis", "jumat"];
  const items = keys.map((h) => ({
    hari: h,
    menu_hari:
      document.querySelector(`.fb-menu[data-hari="${h}"]`)?.value || "",
    pesan_feedback:
      document.querySelector(`.fb-pesan[data-hari="${h}"]`)?.value || "",
  }));
  try {
    const res = await fetch("api/quiz_feedback_harian.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ action: "save_batch", items }),
    });
    const j = await parseApiJson(res);
    showToast(j.pesan || "");
  } catch (_) {
    showToast("Gagal menyimpan feedback");
  }
}

async function adminLoadUsers() {
  const tb = document.querySelector("#tbl-users tbody");
  if (!tb) return;
  tb.innerHTML = "";
  try {
    const res = await fetch("api/admin_petugas.php?action=users", fetchCred);
    const j = await parseApiJson(res);
    if (j.status !== "ok") return;
    const me = window.SIAP_USER?.id;
    (j.data || []).forEach((u) => {
      const tr = document.createElement("tr");
      const del =
        String(u.id) === String(me)
          ? "—"
          : `<button type="button" class="btn-mini-del" onclick="adminDeleteUser(${u.id})">Hapus</button>`;
      tr.innerHTML = `<td>${escapeHtml(u.nama)}</td><td>${escapeHtml(u.email)}</td><td>${escapeHtml(u.role)}</td><td>${del}</td>`;
      tb.appendChild(tr);
    });
  } catch (_) {}
}

async function adminDeleteUser(id) {
  if (!confirm("Hapus pengguna ini?")) return;
  try {
    const res = await fetch("api/admin_petugas.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ action: "user_delete", id }),
    });
    const j = await parseApiJson(res);
    showToast(j.pesan || "");
    adminLoadUsers();
  } catch (_) {
    showToast("Gagal menghapus");
  }
}

async function adminLoadQuiz() {
  const tb = document.querySelector("#tbl-quiz tbody");
  if (!tb) return;
  tb.innerHTML = "";
  try {
    const res = await fetch("api/admin_petugas.php?action=quiz", fetchCred);
    const j = await parseApiJson(res);
    if (j.status !== "ok") return;
    (j.data || []).forEach((r) => {
      const tr = document.createElement("tr");
      tr.innerHTML = `<td>${escapeHtml(r.nama_menu)}</td><td>${escapeHtml(r.petunjuk)}</td><td><button type="button" class="btn-mini-del" onclick="adminQuizDelete(${r.id})">Hapus</button></td>`;
      tb.appendChild(tr);
    });
  } catch (_) {}
}

async function adminQuizAdd() {
  const nama = document.getElementById("adm-quiz-nama")?.value?.trim();
  const petunjuk = document.getElementById("adm-quiz-petunjuk")?.value?.trim();
  try {
    const res = await fetch("api/admin_petugas.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({
        action: "quiz_add",
        nama_menu: nama,
        petunjuk,
      }),
    });
    const j = await parseApiJson(res);
    showToast(j.pesan || "");
    if (j.status === "ok") {
      document.getElementById("adm-quiz-nama").value = "";
      document.getElementById("adm-quiz-petunjuk").value = "";
      adminLoadQuiz();
    }
  } catch (_) {
    showToast("Gagal menambah soal");
  }
}

async function adminQuizDelete(id) {
  if (!confirm("Hapus soal ini?")) return;
  try {
    const res = await fetch("api/admin_petugas.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ action: "quiz_delete", id }),
    });
    const j = await parseApiJson(res);
    showToast(j.pesan || "");
    adminLoadQuiz();
  } catch (_) {}
}

function seninMingguIniStr() {
  const now = new Date();
  const d = now.getDay();
  const delta = d === 0 ? -6 : 1 - d;
  const mon = new Date(now);
  mon.setDate(now.getDate() + delta);
  return localISODate(mon);
}

async function adminPrefillJadwal() {
  const lbl = document.getElementById("adm-minggu-label");
  const mon = seninMingguIniStr();
  if (lbl) {
    lbl.textContent = new Date(mon + "T12:00:00").toLocaleDateString("id-ID", {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric",
    });
  }
  try {
    const res = await fetch("api/jadwal_menu.php", fetchCred);
    const j = await parseApiJson(res);
    if (j.status !== "ok" || !j.hari) return;
    const keys = ["Senin", "Selasa", "Rabu", "Kamis", "Jumat"];
    const ids = ["jm-senin", "jm-selasa", "jm-rabu", "jm-kamis", "jm-jumat"];
    keys.forEach((k, i) => {
      const el = document.getElementById(ids[i]);
      if (el && j.hari[k] !== undefined) el.value = j.hari[k];
    });
    const pes = document.getElementById("jm-pesan");
    if (pes && j.pesan_petugas) pes.value = j.pesan_petugas;
  } catch (_) {}
}

async function simpanJadwalPetugas() {
  const body = {
    minggu_mulai: seninMingguIniStr(),
    senin: document.getElementById("jm-senin")?.value || "",
    selasa: document.getElementById("jm-selasa")?.value || "",
    rabu: document.getElementById("jm-rabu")?.value || "",
    kamis: document.getElementById("jm-kamis")?.value || "",
    jumat: document.getElementById("jm-jumat")?.value || "",
    pesan_petugas: document.getElementById("jm-pesan")?.value || "",
  };
  try {
    const res = await fetch("api/jadwal_menu.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify(body),
    });
    const j = await parseApiJson(res);
    showToast(j.pesan || "");
  } catch (_) {
    showToast("Gagal menyimpan jadwal");
  }
}

async function adminLoadSaran() {
  const tb = document.querySelector("#tbl-saran tbody");
  if (!tb) return;
  tb.innerHTML = "";
  try {
    const res = await fetch("api/saran.php", fetchCred);
    const j = await parseApiJson(res);
    if (j.status !== "ok") return;
    (j.data || []).forEach((r) => {
      const tr = document.createElement("tr");
      const tgl = r.created_at
        ? new Date(r.created_at).toLocaleString("id-ID")
        : "—";
      tr.innerHTML = `<td>${escapeHtml(tgl)}</td><td>${escapeHtml(r.nama_pengguna || "")}</td><td>${escapeHtml(r.jenis)}</td><td class="td-wrap">${escapeHtml(r.isi)}</td>`;
      tb.appendChild(tr);
    });
  } catch (_) {}
}
