(() => {
  const root = document.documentElement;
  const btn = document.querySelector("[data-theme-toggle]");
  const toTop = document.querySelector("[data-to-top]");
  const fabMenu = document.querySelector("[data-fab-menu]");
  const fabMenuToggle = document.querySelector("[data-fab-menu-toggle]");

  const storageKey = "aneater_site_theme";

  const getPreferred = () => {
    const saved = localStorage.getItem(storageKey);
    if (saved === "light" || saved === "dark") return saved;
    return null;
  };

  const apply = (mode) => {
    if (!mode) {
      root.removeAttribute("data-theme");
      return;
    }
    root.setAttribute("data-theme", mode);
  };

  const current = getPreferred();
  if (current) apply(current);

  if (btn) {
    btn.addEventListener("click", () => {
      const mode = root.getAttribute("data-theme");
      const next = mode === "dark" ? "light" : "dark";
      apply(next);
      localStorage.setItem(storageKey, next);
    });
  }

  const isMobileMq = window.matchMedia("(max-width: 640px)");

  const updateFloatingButtons = () => {
    const shouldShow = isMobileMq.matches && window.scrollY > 420;
    if (toTop) toTop.classList.toggle("is-visible", shouldShow);
    if (fabMenu) fabMenu.classList.toggle("is-visible", shouldShow);
    if (!shouldShow && fabMenu) fabMenu.classList.remove("is-open");
  };

  if (toTop || fabMenu) {
    const mq = window.matchMedia("(max-width: 640px)");
    window.addEventListener("scroll", updateFloatingButtons, { passive: true });
    window.addEventListener("resize", updateFloatingButtons);
    mq.addEventListener?.("change", updateFloatingButtons);
    updateFloatingButtons();
  }

  if (toTop) {
    toTop.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  if (fabMenu && fabMenuToggle) {
    fabMenuToggle.addEventListener("click", () => {
      fabMenu.classList.toggle("is-open");
    });

    fabMenu.addEventListener("click", (e) => {
      const link = e.target instanceof Element ? e.target.closest("a") : null;
      if (link) {
        fabMenu.classList.remove("is-open");
      }
    });

    document.addEventListener("click", (e) => {
      if (!fabMenu.classList.contains("is-open")) return;
      if (e.target instanceof Node && fabMenu.contains(e.target)) return;
      fabMenu.classList.remove("is-open");
    });
  }
})();
