#
# * Lift Search
# * https://github.com/voceconnect/lift-search
#
"use strict"
module.exports = (grunt) ->

  # Project configuration.
  grunt.initConfig
    jshint:
      options:
        curly: true
        eqeqeq: true
        eqnull: true
        browser: true
        plusplus: true
        undef: true
        unused: true
        trailing: true
        globals:
          jQuery: true
          $: true
          ajaxurl: true
          Backbone: true,
          "_": true
      all: [
        "js/admin.js"
        "lift-search-form.js"
      ]
    uglify:
      theme:
        options:
          preserveComments: "some"
          mangle: false
        files:
          "js/admin.min.js": [
            "js/admin.js"
          ]
    imagemin:
      theme:
        files: [
          expand: true
          cwd: "img/"
          src: "img/**/*.{png,jpg}"
          dest: "img/"
        ]
    compass:
      options:
        config: "config.rb"
        basePath: "."
        force: true
      production:
        options:
          environment: "production"
      development:
        options:
          environment: "development"
    watch:
      scripts:
        files: "js/**/*.js"
        tasks: [
          "jshint"
        ]
      images:
        files: "img/**/*.{png,jpg,gif}"
        tasks: ["imagemin"]
      styles:
        files: "sass/**/*.scss"
        tasks: ["compass"]
    build:
      production: [
        "uglify"
        "compass:production"
        "composer:install:no-dev:optimize-autoloader:prefer-dist"
      ]
      uat: [
        "uglify"
        "compass:production"
        "composer:install:no-dev:optimize-autoloader:prefer-dist"
      ]
      staging: [
        "compass:development"
        "composer:install"
      ]
      development: [
        "compass:development"
        "composer:install"
      ]


  #load the tasks
  grunt.loadNpmTasks "grunt-voce-plugins"

  #set the default task as the development build
  grunt.registerTask "default", ["build:development"]