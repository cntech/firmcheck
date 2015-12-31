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
    
    var initFilters = function(additional) {
      $scope.filters = {
        'homepages': {
          '$not': '' // not empty
        }
      }
      if(additional) {
        angular.extend($scope.filters, additional)
      }
      $scope.filterInputs = {}
    }
    initFilters()
    
    $scope.setFilter = function(field, value) {
      $scope.filters[field] = value
      $scope.loadPage(0)
    }
    
    $scope.setRatingNameFilter = function() {
      var inputs = $scope.filterInputs
      if(inputs.ratingName) {
        var ratingNames = inputs.ratingName.split(',').map(function(name) {
          return name.trim()
        })
        $scope.filters['r.name'] = {}
        if(ratingNames.length > 1) {
          $scope.filters['r.name']['$in'] = ratingNames
        } else {
          $scope.filters['r.name']['$like'] = '%'+ratingNames[0]+'%'
        }
      } else {
        delete $scope.filters['r.name']
      }
    }
    
    $scope.setRatingValueFilter = function() {
      var inputs = $scope.filterInputs
      if(inputs.ratingValue) {
        var prefix = inputs.ratingValue[0]
        switch(prefix) {
        case '<':
          $scope.filters['r.rating'] = {
            '$lt': inputs.ratingValue.substr(1).trim()
          }
          break;
        case '>':
          $scope.filters['r.rating'] = {
            '$gt': inputs.ratingValue.substr(1).trim()
          }
          break;
        default:
          var ratingValues = inputs.ratingValue.split(',').map(function(value) {
            return value.trim()
          })
          $scope.filters['r.rating'] = {}
          $scope.filters['r.rating']['$in'] = ratingValues
          break;
        }
      } else {
        delete $scope.filters['r.rating']
      }
    }
    
    $scope.setFilters = function() {
      $scope.setRatingNameFilter()
      $scope.setRatingValueFilter()
      $scope.loadPage(0)
    }
    
    $scope.clearFilter = function() {
      initFilters({
        area: $scope.filters.area
      })
      $scope.loadPage(0)
    }
    
    var loadFirms = function() {
      $scope.records = null
      Firm.get({
        filter: JSON.stringify($scope.filters),
        offset: $scope.pagination.offset,
        limit: $scope.pagination.limit
      }, function(result) {
        $scope.queries = {
          query: result.query,
          countQuery: result.count_query
        }
        $scope.records = result.data.map(function(record) {
          record.homepages = record.homepages.split(', ')
          record.rating = {
            value: 'x'
          }
          record.noRatings = true
          loadRatings(record)
          return record
        })
        $scope.statistics = {
          firstRecord: $scope.pagination.offset,
          lastRecord: $scope.pagination.offset + $scope.records.length - 1,
          totalCount: result.total_count
        }
        var pageCount = Math.ceil(($scope.statistics.totalCount * 1.0) / $scope.pagination.limit)
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
    
    $scope.clickRecord = function(record) {
      $scope.clickedRecord = record
    }
    $scope.recordClicked = function(record) {
      if($scope.clickedRecord) {
        return record.id == $scope.clickedRecord.id
      }
    }
    
    $scope.submitRating = function(record) {
      var ratingRecord = new Rating({
        firm_id: record.id,
        name: record.rating.name,
        rating: record.rating.value
      })
      ratingRecord.$save(function() {
        record.rating = {
          value: 'x'
        }
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

