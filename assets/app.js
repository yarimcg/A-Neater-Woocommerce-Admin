(() => {
  const root = document.documentElement;
  const btn = document.querySelector("[data-theme-toggle]");
  const toTop = document.querySelector("[data-to-top]");

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

  if (toTop) {
    const mq = window.matchMedia("(max-width: 640px)");

    const updateToTop = () => {
      const shouldShow = mq.matches && window.scrollY > 420;
      toTop.classList.toggle("is-visible", shouldShow);
    };

    window.addEventListener("scroll", updateToTop, { passive: true });
    window.addEventListener("resize", updateToTop);
    mq.addEventListener?.("change", updateToTop);
    updateToTop();

    toTop.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }
})();
