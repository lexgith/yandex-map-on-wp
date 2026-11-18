/**
 * Инициализация карт для шорткода [yandex_map] и виджета «Яндекс.Карта».
 * Выполняется только на страницах, где плагин вывел контейнеры .ymap.
 * Данные каждой карты лежат в JSON-блоке внутри контейнера; для обратной
 * совместимости поддерживается и запасной способ передачи — base64-атрибут.
 */
(function () {
  "use strict";

  function toJson(data) {
    if (typeof data !== "string" || data.length === 0) {
      return null;
    }
    try {
      return JSON.parse(data);
    } catch (err) {
      return null;
    }
  }

  function decodeBase64(str) {
    if (typeof window === "undefined" || !window.atob) {
      return null;
    }
    try {
      var pair = str.split(".");
      return decodeURIComponent(
        Array.prototype.map
          .call(window.atob(pair[0]), function (c) {
            return "%" + ("00" + c.charCodeAt(0).toString(16)).slice(-2);
          })
          .join("")
      );
    } catch (err) {
      return null;
    }
  }

  function parseData(el) {
    var script = el.querySelector("script.ymap-data");
    if (script && script.textContent) {
      var parsed = toJson(script.textContent.trim());
      if (parsed) {
        return parsed;
      }
    }

    var b64 = el.getAttribute("data-ymap-base64");
    if (b64) {
      var decoded = decodeBase64(b64);
      if (decoded) {
        return toJson(decoded);
      }
    }

    var legacy = el.getAttribute("data-ymap");
    if (legacy) {
      return toJson(legacy);
    }

    return null;
  }

  function noticeFor(el, text) {
    el.innerHTML = "";
    el.classList.add("ymap--empty");
    var p = document.createElement("p");
    p.className = "ymap__notice";
    p.textContent = text;
    el.appendChild(p);
  }

  var DEFAULT_PRESET = "islands#redDotIcon";

  function safeNumber(value) {
    var n = Number(value);
    return isFinite(n) ? n : null;
  }

  function validPreset(preset) {
    if (!preset) {
      return false;
    }
    if (typeof preset !== "string") {
      return false;
    }
    var trimmed = preset.trim();
    if (trimmed.length === 0) {
      return false;
    }
    if (!/^[A-Za-z0-9#_.\-:]+$/.test(trimmed)) {
      return false;
    }
    return true;
  }

  function addMarkers(map, markers, groupMarkers) {
    if (!map || !markers || !Array.isArray(markers) || markers.length === 0) {
      return;
    }

    var useClusterer =
      !!groupMarkers &&
      typeof window.ymaps !== "undefined" &&
      !!window.ymaps.Clusterer;

    var placemarks = [];
    markers.forEach(function (marker) {
      if (!marker || typeof marker !== "object") {
        return;
      }
      var placemark = buildPlacemark(marker);
      if (placemark) {
        placemarks.push(placemark);
      }
    });

    if (placemarks.length === 0) {
      return;
    }

    if (groupMarkers) {
      attachPointGroupBalloons(map, placemarks);
    }

    try {
      if (useClusterer) {
        var Clusterer = window.ymaps.Clusterer;
        var clusterer = new Clusterer({
          preset: "islands#orangeClusterIcons",
          maxZoom: 17,
          minClusterSize: 2,
          gridSize: 64,
          clusterNumbers: [10, 100],
        });

        clusterer.createCluster = function (center, clusterGeoObjects) {
          var cluster = Clusterer.prototype.createCluster.call(
            this,
            center,
            clusterGeoObjects
          );
          if (
            cluster &&
            cluster.events &&
            allSameCoordinates(clusterGeoObjects)
          ) {
            try {
              cluster.options.set("openBalloonOnClick", false);
              cluster.options.set("disableClickZoom", true);
            } catch (err) {
              // Опции кластера — некритично.
            }
            cluster.events.add("click", function () {
              openClusterBalloon(map, cluster, clusterGeoObjects);
            });
          }
          return cluster;
        };

        clusterer.add(placemarks);
        map.geoObjects.add(clusterer);
      } else {
        for (var i = 0; i < placemarks.length; i++) {
          map.geoObjects.add(placemarks[i]);
        }
      }
    } catch (err) {
      // Карта уже создана; ошибки добавления не оставляют контейнер белым.
    }
  }

  function allSameCoordinates(objects) {
    if (!objects || objects.length < 2) {
      return false;
    }
    var first = null;
    for (var i = 0; i < objects.length; i++) {
      var obj = objects[i];
      if (!obj || !obj.geometry) {
        continue;
      }
      var coords = null;
      try {
        var geom = obj.geometry.getCoordinates
          ? obj.geometry.getCoordinates()
          : null;
        if (!geom && typeof obj.geometry === "function") {
          geom = obj.geometry();
        }
        if (!geom) {
          geom = obj.geometry;
        }
        coords = geom && geom.length >= 2 ? geom : null;
      } catch (err) {
        coords = null;
      }
      if (
        !coords ||
        !isFinite(Number(coords[0])) ||
        !isFinite(Number(coords[1]))
      ) {
        continue;
      }
      var key = Number(coords[0]) + "," + Number(coords[1]);
      if (first === null) {
        first = key;
      } else if (key !== first) {
        return false;
      }
    }
    return first !== null;
  }

  function placemarkCoords(obj) {
    if (!obj || !obj.geometry) {
      return null;
    }
    var geom = null;
    try {
      geom = obj.geometry.getCoordinates ? obj.geometry.getCoordinates() : null;
      if (!geom && typeof obj.geometry === "function") {
        geom = obj.geometry();
      }
      if (!geom) {
        geom = obj.geometry;
      }
    } catch (err) {
      geom = null;
    }
    if (
      !geom ||
      geom.length < 2 ||
      !isFinite(Number(geom[0])) ||
      !isFinite(Number(geom[1]))
    ) {
      return null;
    }
    return [Number(geom[0]), Number(geom[1])];
  }

  function pointKey(coords) {
    return coords ? coords[0] + "," + coords[1] : "";
  }

  function openPointGroupBalloon(map, placemarks) {
    if (!map || !map.balloon || !map.balloon.open) {
      var first = placemarks && placemarks[0];
      if (first && first.balloon && first.balloon.open) {
        first.balloon.open();
      }
      return;
    }
    try {
      openClusterBalloon(map, null, placemarks);
    } catch (err) {
      var fallback = placemarks && placemarks[0];
      if (fallback && fallback.balloon && fallback.balloon.open) {
        fallback.balloon.open();
      }
    }
  }

  function attachPointGroupBalloons(map, placemarks) {
    if (
      !map ||
      !placemarks ||
      !Array.isArray(placemarks) ||
      placemarks.length === 0
    ) {
      return;
    }
    var groups = {};
    var order = [];
    for (var i = 0; i < placemarks.length; i++) {
      var pm = placemarks[i];
      var coords = placemarkCoords(pm);
      var key = pointKey(coords);
      if (!key) {
        continue;
      }
      if (!groups[key]) {
        groups[key] = [];
        order.push(key);
      }
      groups[key].push(pm);
    }
    for (var j = 0; j < order.length; j++) {
      var group = groups[order[j]];
      if (group.length < 2) {
        continue;
      }
      var items = group.slice();
      for (var k = 0; k < group.length; k++) {
        var pm = group[k];
        try {
          if (pm.options) {
            pm.options.set("openBalloonOnClick", false);
            pm.options.set("openEmptyBalloonOnClick", false);
          }
        } catch (err) {
          // Опции метки — некритично.
        }
        try {
          if (pm.events) {
            pm.events.add(
              "click",
              (function (items) {
                return function (e) {
                  if (e) {
                    try {
                      e.stopPropagation();
                    } catch (err) {
                      // Предотвращение всплытия клика — некритично.
                    }
                  }
                  openPointGroupBalloon(map, items);
                };
              })(items)
            );
          }
        } catch (err) {
          // Обработчик клика группы — опциональный слой UX.
        }
      }
    }
  }

  function escapeHtml(str) {
    return String(str == null ? "" : str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function clusterItemContent(geoObject) {
    var props = geoObject && geoObject.properties ? geoObject.properties : null;
    var header = "";
    var body = "";
    if (props) {
      try {
        header = String(
          props.get("balloonContentHeader") || props.get("clusterCaption") || ""
        );
      } catch (err) {
        header = "";
      }
      try {
        body = String(
          props.get("balloonContentBody") || props.get("hint") || ""
        );
      } catch (err) {
        body = "";
      }
    }
    var title = escapeHtml(header || "Метка");
    var html =
      '<div class="ymap__cluster-item"><div class="ymap__cluster-item-title">' +
      title +
      "</div>";
    if (body) {
      html +=
        '<div class="ymap__cluster-item-body">' + escapeHtml(body) + "</div>";
    }
    html += "</div>";
    return html;
  }

  function openClusterBalloon(map, cluster, geoObjects) {
    try {
      var list = geoObjects.slice();
      list.sort(function (a, b) {
        var at = "";
        var bt = "";
        try {
          at = String(
            a.properties.get("balloonContentHeader") ||
              a.properties.get("clusterCaption") ||
              ""
          );
        } catch (err) {
          at = "";
        }
        try {
          bt = String(
            b.properties.get("balloonContentHeader") ||
              b.properties.get("clusterCaption") ||
              ""
          );
        } catch (err) {
          bt = "";
        }
        at = at.toLowerCase();
        bt = bt.toLowerCase();
        return at < bt ? -1 : at > bt ? 1 : 0;
      });

      if (list.length === 1) {
        var single = list[0];
        if (single && single.balloon && single.balloon.open) {
          single.balloon.open();
          return;
        }
      }

      var items = [];
      for (var i = 0; i < list.length; i++) {
        items.push(clusterItemContent(list[i]));
      }

      var content = "";
      for (var j = 0; j < items.length; j++) {
        content += items[j];
      }

      try {
        if (map && map.balloon && map.balloon.close) {
          map.balloon.close();
        }
      } catch (err) {
        // Закрытие текущего балуна — некритично.
      }
      var target = map && map.balloon;
      var opts = {
        closeButton: true,
        maxWidth: 360,
        maxHeight: 320,
      };
      if (target && target.setOptions) {
        try {
          target.setOptions(opts);
        } catch (err) {
          // Опции балуна — не критично.
        }
      }
      var coords = null;
      if (cluster && cluster.geometry) {
        coords = placemarkCoords(cluster);
      }
      if (!coords && list.length > 0) {
        coords = placemarkCoords(list[0]);
      }
      if (map && map.balloon && map.balloon.open && coords) {
        try {
          map.balloon.open(coords, content, {});
          return;
        } catch (err) {
          // Пробуем другой способ открытия ниже.
        }
      }
      if (cluster && cluster.balloon && cluster.balloon.open) {
        cluster.balloon.open(content);
      }
    } catch (err) {
      // Балун кластера — опциональный слой UX; карта уже создана.
    }
  }

  function buildPlacemark(marker) {
    var lat = safeNumber(marker.lat);
    var lng = safeNumber(marker.lng);
    if (lat === null || lng === null) {
      return null;
    }
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
      return null;
    }

    var properties = {};
    if (marker.title && String(marker.title).trim().length > 0) {
      properties.balloonContentHeader = String(marker.title);
      properties.clusterCaption = String(marker.title);
    }
    if (marker.hint && String(marker.hint).trim().length > 0) {
      properties.balloonContentBody = String(marker.hint);
    }
    if (marker.label && String(marker.label).trim().length > 0) {
      properties.hintContent = String(marker.label);
    }

    var options = { preset: DEFAULT_PRESET };
    if (validPreset(marker.preset)) {
      options.preset = marker.preset.trim();
    }

    try {
      return new window.ymaps.Placemark([lat, lng], properties, options);
    } catch (err) {
      return null;
    }
  }

  function isWithinScrollable(el, target) {
    var node = target;
    while (node && node !== document && node !== el) {
      var style = null;
      try {
        style = window.getComputedStyle(node);
      } catch (err) {
        style = null;
      }
      if (style) {
        if (style.overflowY === "auto" || style.overflowY === "scroll") {
          return true;
        }
        if (style.position === "fixed") {
          return true;
        }
      }
      node = node.parentNode;
    }
    return false;
  }

  function lockMapScroll(el) {
    if (!el) {
      return;
    }
    el.addEventListener(
      "wheel",
      function (e) {
        try {
          if (!e || e.defaultPrevented) {
            return;
          }
          if (isWithinScrollable(el, e.target)) {
            return;
          }
          e.preventDefault();
        } catch (err) {
          // preventDefault доступен не во всех окружениях.
        }
        return false;
      },
      { passive: false }
    );
  }

  function createMap(el, mapData) {
    var loader = document.createElement("div");
    loader.className = "ymap__load";
    loader.textContent = "Загрузка карты…";
    el.appendChild(loader);

    lockMapScroll(el);

    var center =
      mapData.address && String(mapData.address).trim().length > 0
        ? null
        : [Number(mapData.center[0]), Number(mapData.center[1])];

    var map;
    if (center) {
      try {
        map = new window.ymaps.Map(el, {
          center: center,
          zoom: Number(mapData.zoom),
          controls: ["zoomControl", "fullscreenControl"],
        });
      } catch (err) {
        noticeFor(el, "Не удалось загрузить Яндекс.Карту.");
        return;
      }

      try {
        addMarkers(map, mapData.markers, mapData.group_markers);
      } catch (err) {
        // Карта создана; проблемы одной метки не оставляют контейнер белым.
      }

      if (loader && loader.parentNode) {
        loader.parentNode.removeChild(loader);
      }
      el.classList.add("ymap--ready");
      return;
    }

    window.ymaps.geocode(String(mapData.address).trim()).then(
      function (res) {
        var first = res.geoObjects.get(0);
        if (!first) {
          noticeFor(el, "Не найден адрес: " + mapData.address);
          if (loader && loader.parentNode) {
            loader.parentNode.removeChild(loader);
          }
          return;
        }
        if (loader && loader.parentNode) {
          loader.parentNode.removeChild(loader);
        }
        try {
          map = new window.ymaps.Map(el, {
            center: first.geometry.getCoordinates(),
            zoom: Number(mapData.zoom),
            controls: ["zoomControl", "fullscreenControl"],
          });
        } catch (err) {
          noticeFor(el, "Не удалось загрузить Яндекс.Карту.");
          return;
        }
        try {
          addMarkers(map, mapData.markers, mapData.group_markers);
        } catch (err) {
          // Карта создана; проблемы одной метки не оставляют контейнер белым.
        }
        el.classList.add("ymap--ready");
      },
      function () {
        noticeFor(el, "Не удалось найти адрес: " + mapData.address);
        if (loader && loader.parentNode) {
          loader.parentNode.removeChild(loader);
        }
      }
    );
  }

  function renderAll(root) {
    var elements = root.querySelectorAll(".ymap");
    for (var i = 0; i < elements.length; i++) {
      var mapData = parseData(elements[i]);
      if (!mapData) {
        continue;
      }
      if (
        !mapData.api_key ||
        typeof mapData.api_key !== "string" ||
        mapData.api_key.length === 0
      ) {
        noticeFor(
          elements[i],
          "Яндекс.Карта ещё не настроена: укажите API-ключ в «Яндекс.Карта на WordPress»."
        );
        continue;
      }
      if (
        typeof window.ymaps === "undefined" ||
        !window.ymaps ||
        !window.ymaps.Map
      ) {
        noticeFor(elements[i], "Не удалось загрузить Яндекс.Карту.");
        continue;
      }
      createMap(elements[i], mapData);
    }
  }

  function init() {
    if (
      typeof window.ymaps !== "undefined" &&
      window.ymaps &&
      window.ymaps.ready
    ) {
      window.ymaps.ready(function () {
        renderAll(document);
      });
      return;
    }

    var tries = 0;
    var timer = window.setInterval(function () {
      tries++;
      if (
        typeof window.ymaps !== "undefined" &&
        window.ymaps &&
        window.ymaps.ready
      ) {
        window.clearInterval(timer);
        window.ymaps.ready(function () {
          renderAll(document);
        });
        return;
      }
      if (tries > 60) {
        window.clearInterval(timer);
        renderAll(document);
      }
    }, 250);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
