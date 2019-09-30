/*
 * Storage rental web shop with selection from floormap
 */

/* This links bundled product IDs to lists of storages they contain */
// var bundles = {1677: [1599, 1601], 1649: [1617, 1618], 1651: [1619, 1620, 1621], 1653: [1602, 1603], 1678: [1669, 1670]}
var bundles = {};

function filterBundles(ids) {
  $.each(bundles, function(key, val) {
    if (val.every(elem => idx.indexOf(elem) > -1)) {
      ids.push(key);
      $.each(val, function(i, id) {
        ids.splice(ids.indexOf(id), 1);
      });
    }
  });
  return ids;
}

jQuery(document).ready(function($) {
  $.each(roomData, function(key, data) {
    if (data["available"] == false) {
      $("svg #" + key).addClass("unavailable");
    }
  });
  $("svg .varasto").click(function() {
    if (!$(this).hasClass("reserved") && !$(this).hasClass("unavailable")) {
      $(this).toggleClass("active");
    }
  });
  $("svg .varasto:not(.unavailable)").tooltip({
    content: function() {
      var data = roomData[$(this).attr("id")];
      return (
        '<ul id="tip"><li><span class="tipleft">Koko:</span>' +
        data["size"] +
        ' m²</li><li><span class="tipleft">Hinta:</span>' +
        data["price"] +
        '</li><li><span class="tipleft">Numero:</span>' +
        data["nr"] +
        "</li></ul>"
      );
    },
    track: true,
    position: { my: "bottom-20" },
    show: false
  });

  var today = new Date();
  today.setDate(today.getDate());
  $("input#datepicker")
    .datepicker({
      dateFormat: "dd.mm.yy",
      minDate: 0,
      maxDate: "+6w",
      onSelect: function() {
        updateReserved();
      }
    })
    .datepicker("setDate", today);

  function getDateStrings() {
    var qty = $("input#duration").val();
    var startdate = $("input#datepicker").datepicker("getDate");
    var enddate = $("input#datepicker").datepicker("getDate");
    enddate.setMonth(enddate.getMonth() + parseInt(qty));
    startdate = [
      startdate.getFullYear(),
      ("0" + (startdate.getMonth() + 1)).slice(-2),
      ("0" + startdate.getDate()).slice(-2)
    ].join(".");
    enddate = [
      enddate.getFullYear(),
      ("0" + (enddate.getMonth() + 1)).slice(-2),
      ("0" + enddate.getDate()).slice(-2)
    ].join(".");
    return { start: startdate, end: enddate };
  }

  function goToCart() {
    var qty = $("input#duration").val();
    var dates = getDateStrings();
    var startdate = dates["start"]
      .split(".")
      .reverse()
      .join(".");
    var enddate = dates["end"]
      .split(".")
      .reverse()
      .join(".");
    var count = $("svg .varasto.active").length;
    if (count == 1) {
      var id = $("svg .varasto.active").attr("id");
      window.location.href =
        "https://vuokra.fit-technology.fi/checkout/?add-to-cart=" +
        id +
        "&quantity=" +
        qty +
        "&startdate=" +
        startdate +
        "&enddate=" +
        enddate;
    } else if (count > 1) {
      var ids = [];
      $("svg .varasto.active").each(function(i) {
        ids.push(parseInt($(this).attr("id")));
      });
      if (bundles.length > 0) {
        ids = filterBundles(ids);
      }
      if (ids.length > 1) {
        ids = ids.map(id => id + ":" + qty);
        window.location.href =
          "https://vuokra.fit-technology.fi/checkout/?add-to-cart=" +
          ids +
          "&quantity=" +
          qty +
          "&startdate=" +
          startdate +
          "&enddate=" +
          enddate;
      } else {
        window.location.href =
          "https://vuokra.fit-technology.fi/checkout/?add-to-cart=" +
          ids[0] +
          "&quantity=" +
          qty +
          "&startdate=" +
          startdate +
          "&enddate=" +
          enddate;
      }
    }
  }

  function updateReserved() {
    var dates = getDateStrings();
    var startdate = dates["start"];
    var enddate = dates["end"];

    $.each(reservations, function(key, res) {
      var data = roomData[key];
      if (enddate < res["start"] || startdate > res["end"]) {
        $("svg #" + key)
          .removeClass("reserved")
          .tooltip(
            "option",
            "content",
            '<ul id="tip"><li><span class="tipleft">Koko:</span>' +
              data["size"] +
              ' m²</li><li><span class="tipleft">Hinta:</span>' +
              data["price"] +
              '</li><li><span class="tipleft">Numero:</span>' +
              data["nr"] +
              "</li></ul>"
          );
      } else {
        $("svg #" + key)
          .addClass("reserved")
          .removeClass("active")
          .tooltip(
            "option",
            "content",
            '<ul id="tip"><li><span class="tipleft">Koko:</span>' +
              data["size"] +
              ' m²</li><li><span class="tipleft">Hinta:</span>' +
              data["price"] +
              '</li><li><span class="tipleft">Numero:</span>' +
              data["nr"] +
              '</li><li><span class="tipleft">Vapautuu: </span>' +
              res["end"]
                .split(".")
                .reverse()
                .join(".") +
              "</li></ul>"
          );
      }
    });
  }

  function propagateBundleReservations() {
    var bkeys = Object.keys(bundles);
    $.each(reservations, function(key, res) {
      if (bkeys.includes(key)) {
        $.each(bundles[key], function(i, skey) {
          reservations[skey]["start"] = reservations[key]["start"];
          reservations[skey]["end"] = reservations[key]["end"];
        });
      }
    });
  }

  if (bundles.length > 0) {
    propagateBundleReservations();
  }
  updateReserved();

  $("input#duration").change(function() {
    updateReserved();
  });

  $("#checkoutButton").click(goToCart);
});
