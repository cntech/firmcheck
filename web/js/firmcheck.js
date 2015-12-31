angular.module('firmcheck', ['ngResource'])

angular.module('firmcheck').factory('Firm', ['$resource', function($resource) {
  return $resource('/index.php/firms', {
    filter: '@filter',
    offset: '@offset',
    limit: '@limit'
  }, {
    'get': {
      isArray: false
    },
    'update': {
      method: 'PUT'
    }
  })
}])

angular.module('firmcheck').factory('Area', ['$resource', function($resource) {
  return $resource('/index.php/areas', {}, {
    'get': {
      isArray: true
    }
  })
}])

angular.module('firmcheck').factory('Rating', ['$resource', function($resource) {
  return $resource('/index.php/firms/:firm_id/ratings', {
    firm_id: '@firm_id',
    filter: '@filter',
    offset: '@offset',
    limit: '@limit'
  }, {
    'get': {
      isArray: true
    },
    'update': {
      method: 'PUT'
    }
  })
}])

angular.module('firmcheck').controller('dashboard', [
  '$scope', 'Firm', 'Area', 'Rating', function($scope, Firm, Area, Rating) {
    
    SCOPE = $scope
    
    $scope.pagination = {
      page: 0,
      offset: 0,
      limit: 100
    }
    
    $scope.filters = {
      '!homepages': ''
    }
    
    $scope.setFilter = function(field, value) {
      $scope.filters[field] = value
      $scope.loadPage(0)
    }
    
    var loadFirms = function() {
      $scope.records = []
      Firm.get({
        filter: JSON.stringify($scope.filters),
        offset: $scope.pagination.offset,
        limit: $scope.pagination.limit
      }, function(result) {
        $scope.records = result.data.map(function(record) {
          record.homepages = record.homepages.split(', ')
          record.rating = {}
          record.noRatings = true
          loadRatings(record)
          return record
        })
        var pageCount = Math.ceil((result.total_count * 1.0) / $scope.pagination.limit)
        var pages = []
        for(var i = 0; i < pageCount; i++) {
          pages.push({
            index: i,
            name: i + 1
          })
        }
        $scope.pagination.pages = pages
        
      })
    }
    loadFirms()
    
    var loadAreas = function() {
      Area.get({}, function(records) {
        $scope.areas = records
      })
    }
    loadAreas()
    
    $scope.areaActive = function(area) {
      return $scope.filters['area'] == area
    }
    
    var loadRatings = function(record) {
      Rating.get({
        firm_id: record.id,
      }, function(ratings) {
        record.ratings = ratings
        record.noRatings = false
      })
    }
    
    $scope.loadPage = function(page) {
      var pagination = $scope.pagination
      pagination.page = page
      pagination.offset = page * pagination.limit
      loadFirms()
    }
    
    $scope.pageActive = function(page) {
      return page == $scope.pagination.page
    }
    
    $scope.submitRating = function(record) {
      var ratingRecord = new Rating({
        firm_id: record.id,
        name: record.rating.name,
        rating: record.rating.value
      })
      ratingRecord.$save(function() {
        record.rating = {}
        loadRatings(record)
      })
    }
    
    $scope.deleteRating = function(firmRecord, ratingRecord) {
      UIkit.modal('#delete-rating-modal').show()
      $scope.deleteRatingModal = {
        firmRecord: firmRecord,
        ratingRecord: ratingRecord
      }
    }
    
    $scope.hideDeleteRatingModal = function() {
      UIkit.modal("#delete-rating-modal").hide()
    }
    
    $scope.forceDeleteRating = function() {
      var firmRecord = $scope.deleteRatingModal.firmRecord
      var ratingRecord = $scope.deleteRatingModal.ratingRecord
      Rating.delete({
        firm_id: ratingRecord.firm_id,
        id: ratingRecord.id
      }, function() {
        $scope.hideDeleteRatingModal()
        loadRatings(firmRecord)
      })
    }
    
  }
])

