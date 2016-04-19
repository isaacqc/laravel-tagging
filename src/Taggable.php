<?php

namespace Conner\Tagging;

use Conner\Tagging\Contracts\TaggingUtility;
use Conner\Tagging\Events\TagAdded;
use Conner\Tagging\Events\TagRemoved;
use Conner\Tagging\Model\Tag;
use Conner\Tagging\Model\Tagged;
use Illuminate\Database\Eloquent\Collection;

/**
 * Copyright (C) 2014 Robert Conner
 */
trait Taggable
{
	/** @var \Conner\Tagging\Contracts\TaggingUtility **/
	static $taggingUtility;

    /**
     * Temp storage for auto tag
     *
     * @var mixed
     * @access protected
     */
    protected $autoTagTmp;

    /**
     * Track if auto tag has been manually set
     *
     * @var boolean
     * @access protected
     */
    protected $autoTagSet = false;
	
	/**
	 * Boot the soft taggable trait for a model.
	 *
	 * @return void
	 */
	public static function bootTaggable()
	{
		if(static::untagOnDelete()) {
			static::deleting(function($model) {
				$model->untag();
			});
		}

        static::saved(function ($model) {
            $model->autoTagPostSave();
        });

		static::$taggingUtility = app(TaggingUtility::class);
	}
	
	/**
	 * Return collection of tagged rows related to the tagged model
	 *
	 * @return Illuminate\Database\Eloquent\Collection
	 */
	public function tagged()
	{
		return $this->morphMany('Conner\Tagging\Model\Tagged', 'taggable')->with('tag');
	}

	/**
	 * Return collection of tags related to the tagged model
	 * TODO : I'm sure there is a faster way to build this, but
	 * If anyone knows how to do that, me love you long time.
	 *
	 * @return Illuminate\Database\Eloquent\Collection
	 */
	public function getTagsAttribute()
	{
		return $this->tagged->map(function($item){
			return $item->tag;
		});
	}
	
	/**
	 * Set the tag names via attribute, example $model->tag_names = 'foo, bar';
	 *
	 * @param string $value
	 */
	public function getTagNamesAttribute($value)
	{
		return implode(', ', $this->tagNames());
	}
	
	/**
	 * Perform the action of tagging the model with the given string
	 *
	 * @param $tagName string or array
	 */
	public function tag($tagNames, $tagCategory = null)
	{
		$tagNames = static::$taggingUtility->makeTagArray($tagNames);
		
		foreach($tagNames as $tagName) {
			$this->addTag($tagName, $tagCategory);
		}
	}
	
	/**
	 * Return array of the tag names related to the current model
	 *
	 * @return array
	 */
	public function tagNames($tagCategory = null)
	{
		return $this->tagged
			->filter(function ($item) use ($tagCategory) {
				return $item->tag->category == $tagCategory;
			})
			->map(function($item){
				return $item->tag->name;
			})->toArray();
	}

	/**
	 * Return array of the tag slugs related to the current model
	 *
	 * @return array
	 */
	public function tagSlugs($tagCategory = null)
	{
		return $this->tagged
			->filter(function ($item) use ($tagCategory) {
				return $item->tag->category == $tagCategory;
			})
			->map(function($item){
				return $item->tag->slug;
			})->toArray();
	}
	
	/**
	 * Remove the tag from this model
	 *
	 * @param $tagName string or array (or null to remove all tags)
	 */
	public function untag($tagNames=null, $tagCategory = null)
	{
		if(is_null($tagNames)) {
			$tagNames = $this->tagNames();
		}
		
		$tagNames = static::$taggingUtility->makeTagArray($tagNames);
		
		foreach($tagNames as $tagName) {
			$this->removeTag($tagName, $tagCategory);
		}
		
		if(static::shouldDeleteUnused()) {
			static::$taggingUtility->deleteUnusedTags();
		}
	}
	
	/**
	 * Replace the tags from this model
	 *
	 * @param $tagName string or array
	 */
	public function retag($tagNames, $tagCategory = null)
	{
		$tagNames = static::$taggingUtility->makeTagArray($tagNames);
		$currentTagNames = $this->tagNames();
		
		$deletions = array_diff($currentTagNames, $tagNames);
		$additions = array_diff($tagNames, $currentTagNames);
		
		$this->untag($deletions, $tagCategory);

		foreach($additions as $tagName) {
			$this->addTag($tagName, $tagCategory);
		}
	}
	
	/**
	 * Filter model to subset with the given tags
	 *
	 * @param $tagNames array|string
	 */
	public function scopeWithAllTags($query, $tagNames, $tagCategory = null)
	{
		if(!is_array($tagNames)) {
			$tagNames = func_get_args();
			array_shift($tagNames);
		}
		
		$className = $query->getModel()->getMorphClass();

		// prepare normalizer
		$tagNames = static::$taggingUtility->makeTagArray($tagNames);
		$normalizer = config('tagging.normalizer');
		$normalizer = $normalizer ?: [static::$taggingUtility, 'slug'];


		foreach($tagNames as $tagSlug) {
			$taggeds = Tagged::join('tagging_tags', 'tag_id', '=', 'tagging_tags.id')
				->where('slug', call_user_func($normalizer, $tagSlug))
				->where('category', '=', $tagCategory)
				->where('taggable_type', $className)
				->lists('taggable_id');
		
			$primaryKey = $this->getKeyName();
			$query->whereIn($this->getTable().'.'.$primaryKey, $taggeds);
		}
		
		return $query;
	}
	
	/**
	 * Filter model to subset with the given tags
	 *
	 * @param $tagNames array|string
	 */
	public function scopeWithAnyTag($query, $tagNames, $tagCategory = null)
	{
		if(!is_array($tagNames)) {
			$tagNames = func_get_args();
			array_shift($tagNames);
		}

		$className = $query->getModel()->getMorphClass();

		// normalize tag names
		$tagNames = static::$taggingUtility->makeTagArray($tagNames);
		$normalizer = config('tagging.normalizer');
		$normalizer = $normalizer ?: [static::$taggingUtility, 'slug'];
		$tagNames = array_map($normalizer, $tagNames);

		$taggeds = Tagged::join('tagging_tags', 'tag_id', '=', 'tagging_tags.id')
			->whereIn('slug', $tagNames)
			->where('category', '=', $tagCategory)
			->where('taggable_type', $className)
			->lists('taggable_id');
		
		$primaryKey = $this->getKeyName();
		return $query->whereIn($this->getTable().'.'.$primaryKey, $taggeds);
	}
	
	/**
	 * Adds a single tag
	 *
	 * @param $tagName string
	 */
	private function addTag($tagName, $tagCategory = null)
	{

		// normalize tag name into slug
		$tagName = trim($tagName);
		$normalizer = config('tagging.normalizer');
		$normalizer = $normalizer ?: [static::$taggingUtility, 'slug'];
		$tagSlug = call_user_func($normalizer, $tagName);
		
		// find the tag
		$tag = Tag::where('slug', '=', $tagSlug)
			->where('category', '=', $tagCategory)
			->first();

		// if tag exists, find the tagged exists
		if ($tag) {

			// if tagged is found
			$previousCount = $this->tagged()->where('tag_id', '=', $tag->id)->take(1)->count();
			if($previousCount >= 1) { return; }
		}
		
		$displayer = config('tagging.displayer');
		$displayer = empty($displayer) ? '\Illuminate\Support\Str::title' : $displayer;
		
		// incrememt count of the tag
		$tagId = static::$taggingUtility->incrementCount($tagName, $tagSlug, $tagCategory);

		// create tagged for this taggable
		$tagged = new Tagged(array(
			'tag_id'=>$tagId,
		));
		$this->tagged()->save($tagged);

		unset($this->relations['tagged']);
		event(new TagAdded($this));
	}
	
	/**
	 * Removes a single tag
	 *
	 * @param $tagName string
	 */
	private function removeTag($tagName, $tagCategory = null)
	{

		// normalize tag name into slug
		$tagName = trim($tagName);
		$normalizer = config('tagging.normalizer');
		$normalizer = $normalizer ?: [static::$taggingUtility, 'slug'];
		$tagSlug = call_user_func($normalizer, $tagName);
		
		// find the tag
		$tag = Tag::where('slug', '=', $tagSlug)
			->where('category', '=', $tagCategory)
			->first();

		// if tag exists, find the tagged exists
		if ($tag) {

			// decrememnt count of tag if tagged can be removed
			if($this->tagged()->where('tag_id', '=', $tag->id)->delete()) {
				static::$taggingUtility->decrementCount($tag->id);
			}
			
			unset($this->relations['tagged']);
			event(new TagRemoved($this));
		}
	}

	/**
	 * Return an array of all of the tags that are in use by this model
	 *
	 * @return Collection
	 */
	public static function existingTags($tagCategory = null)
	{
		return Tagged::distinct()
			->join('tagging_tags', 'tag_id', '=', 'tagging_tags.id')
			->where('category', '=', $tagCategory)
			->where('taggable_type', '=', (new static)->getMorphClass())
			->orderBy('slug', 'ASC')
			->get(array('slug as slug', 'name as name', 'count as count'));
	}
	
	/**
	 * Should untag on delete
	 */
	public static function untagOnDelete()
	{
		return isset(static::$untagOnDelete)
			? static::$untagOnDelete
			: config('tagging.untag_on_delete');
	}
	
	/**
	 * Delete tags that are not used anymore
	 */
	public static function shouldDeleteUnused()
	{
		return config('tagging.delete_unused_tags');
	}

    /**
     * Set tag names to be set on save
     *
     * @param mixed $value Data for retag
     *
     * @return void
     *
     * @access public
     */
    public function setTagNamesAttribute($value)
    {
        $this->autoTagTmp = $value;
        $this->autoTagSet = true;
    }

    /**
     * AutoTag post-save hook
     *
     * Tags model based on data stored in tmp property, or untags if manually
     * set to falsey value
     *
     * @return void
     *
     * @access public
     */
    public function autoTagPostSave()
    {
        if ($this->autoTagSet) {
            if ($this->autoTagTmp) {
                $this->retag($this->autoTagTmp);
            } else {
                $this->untag();
            }
        }
    }
}
